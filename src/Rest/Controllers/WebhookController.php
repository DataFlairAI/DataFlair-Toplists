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
use DataFlair\Toplists\Webhooks\WebhookSignatureVerifier;

final class WebhookController
{
    private const TIMESTAMP_TOLERANCE_SECONDS = 300;

    public function __construct(
        private WebhookSignatureVerifier $verifier,
        private WebhookEventsRepositoryInterface $events,
        private ToplistPersisterInterface $toplistFetcher,
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
        $rawBody = $request->get_body();
        $signature = (string) ($request->get_header('X-DataFlair-Signature') ?? '');
        $secret = trim((string) get_option('dataflair_webhook_secret', ''));

        if (!$this->verifier->verify($rawBody, $signature, $secret)) {
            $this->recordRejected('invalid signature');
            $this->logger->warning('Webhook: rejected, invalid signature');
            return new \WP_REST_Response(['error' => 'invalid_signature'], 401);
        }

        $data = json_decode($rawBody, true);
        if (!is_array($data) || !isset($data['delivery_id'], $data['event']) || !is_string($data['delivery_id']) || !is_string($data['event'])) {
            $this->recordRejected('malformed payload');
            $this->logger->warning('Webhook: rejected, malformed payload');
            return new \WP_REST_Response(['error' => 'malformed_payload'], 400);
        }

        $occurredAt = is_string($data['occurred_at'] ?? null) ? $data['occurred_at'] : null;
        if (!$this->isFreshTimestamp($occurredAt)) {
            $this->recordRejected('stale or missing timestamp');
            $this->logger->warning('Webhook: rejected, stale timestamp: ' . (string) $occurredAt);
            return new \WP_REST_Response(['error' => 'stale_timestamp'], 401);
        }

        $deliveryId = $data['delivery_id'];
        $eventType  = $data['event'];

        if ($this->events->hasProcessed($deliveryId)) {
            $this->logger->info('Webhook: delivery ' . $deliveryId . ' already processed, no-op');
            return new \WP_REST_Response(['status' => 'already_processed'], 200);
        }

        // Fails closed: a request must give the plugin certainty that it's
        // scoped to this exact tenant, so an unresolvable expected host (no
        // API base URL configured) rejects too, rather than skipping the
        // check like an implicit pass.
        $expectedHost = parse_url($this->baseUrlDetector->detect(false), PHP_URL_HOST);
        $actualHost   = is_string($data['tenant_host'] ?? null) ? $data['tenant_host'] : null;
        if ($expectedHost === null || $actualHost !== $expectedHost) {
            $reason = $expectedHost === null
                ? 'tenant host cannot be verified: no API base URL configured'
                : 'tenant host mismatch: expected ' . $expectedHost . ', got ' . ($actualHost ?? 'none');
            $this->recordRejected($reason);
            $this->logger->error('Webhook: ' . $reason);
            return new \WP_REST_Response(['error' => 'tenant_mismatch'], 409);
        }

        $payload = is_array($data['data'] ?? null) ? $data['data'] : [];
        $this->routeEvent($eventType, $payload);

        if (!$this->events->recordProcessed($deliveryId, $eventType)) {
            $this->logger->error('Webhook: failed to record delivery ' . $deliveryId . ' in the idempotency ledger — a retry will be processed again');
        }
        update_option('dataflair_webhook_last_processed_at', current_time('mysql'));

        return new \WP_REST_Response(['status' => 'processed'], 200);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function routeEvent(string $eventType, array $payload): void
    {
        if ($eventType === 'toplist.published') {
            $toplistId = (int) ($payload['toplist_id'] ?? 0);
            if ($toplistId > 0) {
                $endpoint = rtrim($this->baseUrlDetector->detect(false), '/') . '/toplists/' . $toplistId;
                $this->toplistFetcher->fetchAndStore($endpoint, $this->token);
            }
            return;
        }

        if ($eventType === 'brand.status_changed' || $eventType === 'brand.updated') {
            $brandId = (int) ($payload['brand_id'] ?? 0);
            if ($brandId > 0) {
                $this->brandSync->syncOne($brandId);
            }
            return;
        }

        $this->logger->info('Webhook: no handler for event type ' . $eventType . ', ignoring');
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
}
