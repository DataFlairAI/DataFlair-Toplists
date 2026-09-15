<?php
/**
 * Contract for the webhook idempotency ledger, backed by
 * `wp_dataflair_webhook_events`. A known delivery_id means the receiver
 * already ran this exact delivery to completion.
 *
 * @package DataFlair\Toplists\Webhooks
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Webhooks;

interface WebhookEventsRepositoryInterface
{
    public function hasProcessed(string $deliveryId): bool;

    public function recordProcessed(string $deliveryId, string $eventType): bool;

    /**
     * Acquires a connection-scoped advisory lock keyed on this delivery_id.
     * Closes the TOCTOU window between hasProcessed() and recordProcessed():
     * two requests carrying the same delivery_id (a genuine duplicate
     * delivery, or the sender's own retry racing a still-in-flight first
     * attempt) would otherwise both read "not yet processed" and both run
     * the handler. Returns false when another request already holds it -
     * the caller should treat that as "already being handled" and no-op
     * rather than wait, since the lock can be held for as long as the
     * handler's own outbound HTTP calls take.
     */
    public function acquireLock(string $deliveryId): bool;

    /**
     * Releases a lock taken by acquireLock(). Always call from a finally
     * block: the lock is also auto-released if the connection closes (a PHP
     * fatal error included), so a crash mid-handler can never leave it
     * stuck - a subsequent retry finds no processed row and no lock, and
     * proceeds normally.
     */
    public function releaseLock(string $deliveryId): void;
}
