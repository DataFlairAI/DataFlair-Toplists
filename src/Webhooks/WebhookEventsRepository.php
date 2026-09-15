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

    public function acquireLock(string $deliveryId): bool
    {
        // 1-second wait: a genuine concurrent duplicate should fail fast
        // into the "already being handled" response, not queue up behind
        // whatever the in-flight request's own outbound HTTP calls take.
        $result = $this->wpdb->get_var(
            $this->wpdb->prepare('SELECT GET_LOCK(%s, 1)', $this->lockName($deliveryId))
        );

        return (string) $result === '1';
    }

    public function releaseLock(string $deliveryId): void
    {
        $this->wpdb->query(
            $this->wpdb->prepare('SELECT RELEASE_LOCK(%s)', $this->lockName($deliveryId))
        );
    }

    // MySQL named locks cap at 64 characters. delivery_id is varchar(36)
    // (a UUID today) and would fit as-is, but hashing keeps this correct
    // regardless of what the sender ever puts in that field.
    private function lockName(string $deliveryId): string
    {
        return 'dataflair_webhook_' . sha1($deliveryId);
    }
}
