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
}
