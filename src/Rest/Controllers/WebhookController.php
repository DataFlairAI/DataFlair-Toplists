<?php
/**
 * Controller for POST /wp-json/dataflair/v1/webhooks.
 *
 * The plugin's first unauthenticated-but-signed route: RestRouter registers
 * it with permission_callback => '__return_true', all auth handled here via
 * HMAC signature verification instead of a WP capability check (the caller
 * is DataFlair's queue worker, not a logged-in WP user).
 *
 * Order: signature -> payload parse -> timestamp -> idempotency -> tenant
 * guard -> route to handler. Thin webhook, fat fetch: the payload carries
 * ids only, handlers re-fetch through the plugin's normal sync services.
 *
 * Freshness is checked against the payload's own `occurred_at` field, not
 * an X-DataFlair-Timestamp header - a header is never part of what the
 * signature hashes (verify() only covers the raw body), so checking it
 * would let a captured (body, signature) pair be replayed indefinitely by
 * just forging a new header value.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Rest\Controllers;

use DataFlair\Toplists\Http\ApiBaseUrlDetector;
use DataFlair\Toplists\Logging\LoggerInterface;
use DataFlair\Toplists\Sync\BrandSyncServiceInterface;
use DataFlair\Toplists\Sync\ToplistPersisterInterface;
use DataFlair\Toplists\Webhooks\WebhookEventsRepositoryInterface;
use DataFlair\Toplists\Webhooks\WebhookSignatureVerifierInterface;

final class WebhookController
{
    private const TIMESTAMP_TOLERANCE_SECONDS = 300;

    public function __construct(
        private WebhookSignatureVerifierInterface $verifier,
        private WebhookEventsRepositoryInterface $events,
        private ToplistPersisterInterface $toplistPersister,
        private BrandSyncServiceInterface $brandSync,
        private ApiBaseUrlDetector $baseUrlDetector,
        private string $token,
        private LoggerInterface $logger
    ) {
    }

    /**
     * @return \WP_REST_Response
     */
    public function receive(\WP_REST_Request $request)
    {
        if (get_option('dataflair_webhook_enabled', '0') !== '1') {
            // The admin turned "Enable webhook sync" off - possibly after
            // this route was already subscribed on the sender side, so a
            // delivery can still arrive here. Reject before doing any
            // signature-verification work rather than silently continuing
            // to act on a feature the admin explicitly disabled.
            return $this->reject(
                'webhook sync is disabled in settings',
                'Webhook: rejected, webhook sync is disabled',
                'webhook_disabled',
                403,
                'info'
            );
        }

        $rawBody = $request->get_body();
        $signature = (string) ($request->get_header('X-DataFlair-Signature') ?? '');
        $secret = trim((string) get_option('dataflair_webhook_secret', ''));

        if (!$this->verifier->verify($rawBody, $signature, $secret)) {
            // recordStatus: false - this is the one rejection reachable by
            // anyone on the internet with zero knowledge of the shared
            // secret (every other rejection below requires a valid
            // signature to even be reached). Letting it write to the
            // admin-facing "last rejected" status would let scanner/bot
            // noise permanently show the webhook as failing even while
            // real signed deliveries succeed.
            return $this->reject('invalid signature', 'Webhook: rejected, invalid signature', 'invalid_signature', 401, 'warning', false);
        }

        $data = json_decode($rawBody, true);
        if (!is_array($data) || !isset($data['delivery_id'], $data['event']) || !is_string($data['delivery_id']) || !is_string($data['event'])) {
            return $this->reject('malformed payload', 'Webhook: rejected, malformed payload', 'malformed_payload', 400);
        }

        $occurredAt = is_string($data['occurred_at'] ?? null) ? $data['occurred_at'] : null;
        if (!$this->isFreshTimestamp($occurredAt)) {
            return $this->reject(
                'stale or missing timestamp',
                'Webhook: rejected, stale timestamp: ' . (string) $occurredAt,
                'stale_timestamp',
                401
            );
        }

        $deliveryId = $data['delivery_id'];
        $eventType  = $data['event'];

        // Closes the TOCTOU window between hasProcessed() and
        // recordProcessed() below: without this, two requests carrying the
        // same delivery_id (a genuine duplicate delivery, or the sender's
        // own retry racing a still-in-flight first attempt) could both read
        // "not yet processed" and both run the handler. Not waiting on a
        // contended lock is deliberate - a concurrent request should fail
        // fast into "already being handled", not queue up behind whatever
        // the in-flight request's own outbound HTTP calls take.
        if (!$this->events->acquireLock($deliveryId)) {
            $this->logger->info('Webhook: delivery ' . $deliveryId . ' is already being processed by a concurrent request, no-op');
            return new \WP_REST_Response(['status' => 'already_processing'], 200);
        }

        try {
            if ($this->events->hasProcessed($deliveryId)) {
                $this->logger->info('Webhook: delivery ' . $deliveryId . ' already processed, no-op');
                return new \WP_REST_Response(['status' => 'already_processed'], 200);
            }

            // Fails closed: a request must give the plugin certainty that
            // it's scoped to this exact tenant, so an unconfigured site
            // rejects too, rather than skipping the check like an implicit
            // pass.
            //
            // SECURITY: detectConfiguredHost() (not detect()) is required
            // here. detect() never returns empty - it falls back to a
            // hard-coded DataFlair host when unconfigured - so
            // parse_url(detect(...)) always yields SOME host. Composing
            // isConfigured() + detect() ad-hoc here previously left a real
            // bypass: when the configured base URL was non-empty but
            // unparseable (e.g. missing a scheme), $expectedHost resolved to
            // null; if the payload also omitted tenant_host, $actualHost was
            // also null, and `null !== null` is false, so the mismatch check
            // silently passed. Verified this was exploitable before the fix
            // (malformed local config + a payload with no tenant_host
            // reached routeEvent() with a 200 response).
            $expectedHost = $this->baseUrlDetector->detectConfiguredHost(false);
            // Hostnames are case-insensitive; detectConfiguredHost() already
            // lowercases its side, so the payload's value is normalized the
            // same way rather than doing a case-sensitive strict compare.
            $actualHost = is_string($data['tenant_host'] ?? null) ? strtolower($data['tenant_host']) : null;
            if ($expectedHost === null || $actualHost === null || $actualHost !== $expectedHost) {
                $reason = $expectedHost === null
                    ? 'tenant host cannot be verified: no valid API base URL configured'
                    : 'tenant host mismatch: expected ' . $expectedHost . ', got ' . ($actualHost ?? 'none');
                return $this->reject($reason, 'Webhook: ' . $reason, 'tenant_mismatch', 409, 'error');
            }

            $payload = is_array($data['data'] ?? null) ? $data['data'] : [];
            if (! $this->routeEvent($eventType, $payload)) {
                // Do NOT record this delivery_id as processed: the whole
                // point of a non-2xx here is to make the sender's own retry
                // policy (DeliverWebhookJob: 4 attempts, 30s/5min/30min
                // backoff) kick in. Marking it processed on a failed sync
                // would both hide the failure from the sender (it sees
                // "done", never retries) and permanently block a legitimate
                // retry of this same delivery_id via the idempotency ledger.
                $this->logger->error('Webhook: handler failed for delivery ' . $deliveryId . ' (' . $eventType . '), not recording as processed so a retry can succeed');
                return new \WP_REST_Response(['status' => 'processing_failed'], 502);
            }

            if (!$this->events->recordProcessed($deliveryId, $eventType)) {
                $this->logger->error('Webhook: failed to record delivery ' . $deliveryId . ' in the idempotency ledger — a retry will be processed again');
            }
            update_option('dataflair_webhook_last_processed_at', current_time('mysql'));

            return new \WP_REST_Response(['status' => 'processed'], 200);
        } finally {
            $this->events->releaseLock($deliveryId);
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return bool False means the sender should retry: either the payload
     *     was missing the id this event type needs, or the downstream fetch/
     *     sync itself failed. An unhandled event type is NOT a failure -
     *     retrying would never add a handler, so it returns true (processed).
     */
    private function routeEvent(string $eventType, array $payload): bool
    {
        if ($eventType === 'toplist.published') {
            $toplistId = (int) ($payload['toplist_id'] ?? 0);
            if ($toplistId <= 0) {
                $this->logger->warning('Webhook: toplist.published payload missing a valid toplist_id, cannot sync');
                return false;
            }
            $endpoint = rtrim($this->baseUrlDetector->detect(false), '/') . '/toplists/' . $toplistId;
            return $this->toplistPersister->fetchAndStore($endpoint, $this->token);
        }

        if ($eventType === 'brand.status_changed' || $eventType === 'brand.updated') {
            $brandId = (int) ($payload['brand_id'] ?? 0);
            if ($brandId <= 0) {
                $this->logger->warning('Webhook: ' . $eventType . ' payload missing a valid brand_id, cannot sync');
                return false;
            }
            return $this->brandSync->syncOne($brandId)->status !== 'failed';
        }

        $this->logger->info('Webhook: no handler for event type ' . $eventType . ', ignoring');
        return true;
    }

    private function isFreshTimestamp(?string $timestamp): bool
    {
        if ($timestamp === null || $timestamp === '') {
            return false;
        }

        $time = strtotime($timestamp);
        if ($time === false) {
            return false;
        }

        return abs(time() - $time) <= self::TIMESTAMP_TOLERANCE_SECONDS;
    }

    private function recordRejected(string $reason): void
    {
        update_option('dataflair_webhook_last_rejected_at', current_time('mysql'));
        update_option('dataflair_webhook_last_rejected_reason', $reason);
    }

    /**
     * Records the rejection, logs it, and builds the error response - the
     * shared tail of every rejection branch in receive(). $logMessage is
     * taken explicitly rather than derived from $reason because the two
     * diverge per call site (extra context appended, differing prefixes).
     */
    private function reject(string $reason, string $logMessage, string $errorCode, int $status, string $level = 'warning', bool $recordStatus = true): \WP_REST_Response
    {
        if ($recordStatus) {
            $this->recordRejected($reason);
        }
        $this->logger->{$level}($logMessage);

        return new \WP_REST_Response(['error' => $errorCode], $status);
    }
}
