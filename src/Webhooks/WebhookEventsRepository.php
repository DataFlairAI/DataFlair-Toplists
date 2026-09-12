<?php
/**
 * Idempotency ledger backed by `$wpdb`, mirrors ToplistsRepository's
 * constructor-injection pattern.
 *
 * @package DataFlair\Toplists\Webhooks
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Webhooks;

final class WebhookEventsRepository implements WebhookEventsRepositoryInterface
{
    private \wpdb $wpdb;
    private string $table;

    public function __construct(?\wpdb $wpdb = null)
    {
        if ($wpdb instanceof \wpdb) {
            $this->wpdb = $wpdb;
        } else {
            global $wpdb;
            /** @var \wpdb $wpdb */
            $this->wpdb = $wpdb;
        }
        $this->table = $this->wpdb->prefix . \DATAFLAIR_WEBHOOK_EVENTS_TABLE_NAME;
    }

    public function hasProcessed(string $deliveryId): bool
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT delivery_id FROM {$this->table} WHERE delivery_id = %s LIMIT 1",
                $deliveryId
            )
        );

        return $row !== null;
    }

    public function recordProcessed(string $deliveryId, string $eventType): bool
    {
        $result = $this->wpdb->insert(
            $this->table,
            [
                'delivery_id'  => $deliveryId,
                'event_type'   => $eventType,
                'processed_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%s']
        );

        return $result !== false;
    }
}
