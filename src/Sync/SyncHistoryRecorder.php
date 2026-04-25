<?php
/**
 * Phase 9.6 (admin UX redesign) — capped FIFO history of sync events.
 *
 * Subscribes to the existing `dataflair_sync_batch_finished` and
 * `dataflair_sync_item_failed` actions emitted by {@see BrandSyncService}
 * and {@see ToplistSyncService}, and persists a compact, capped history
 * to the `dataflair_sync_history` option for the Dashboard "Recent
 * activity" card and the Tools → Logs tab fallback.
 *
 * Single responsibility: turn sync events into a capped option entry.
 * The sync services do not need to know about it; wiring is via WP hooks.
 *
 * Storage shape (each entry):
 *   [
 *     'ts'     => int unix timestamp (UTC),
 *     'status' => 'success' | 'partial' | 'error',
 *     'title'  => human-readable headline ("Brands sync · page 3"),
 *     'detail' => secondary line ("12 synced · 0 errors · 4.2s"),
 *     'source' => 'brands' | 'toplists',
 *   ]
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Sync;

final class SyncHistoryRecorder
{
    public const OPTION_KEY = 'dataflair_sync_history';
    public const MAX_ENTRIES = 50;

    public function register(): void
    {
        if (!function_exists('add_action')) {
            return;
        }
        add_action('dataflair_sync_batch_finished', [$this, 'onBatchFinished']);
        add_action('dataflair_sync_item_failed', [$this, 'onItemFailed']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function onBatchFinished(array $payload): void
    {
        $type    = (string) ($payload['type'] ?? '');
        if ($type !== 'brands' && $type !== 'toplists') {
            return;
        }

        $partial    = !empty($payload['partial']);
        $items_done = (int) ($payload['items_done'] ?? 0);
        $errors     = (int) ($payload['errors'] ?? 0);
        $elapsed    = (float) ($payload['elapsed_seconds'] ?? 0.0);
        $page       = (int) ($payload['page'] ?? 0);

        $status = $partial ? 'partial' : ($errors > 0 ? 'partial' : 'success');

        $title  = ucfirst($type) . ' sync · page ' . $page;
        $detail = sprintf(
            '%d synced · %d error%s · %ss',
            $items_done,
            $errors,
            $errors === 1 ? '' : 's',
            number_format($elapsed, 1)
        );

        $this->push([
            'ts'     => time(),
            'status' => $status,
            'title'  => $title,
            'detail' => $detail,
            'source' => $type,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function onItemFailed(array $payload): void
    {
        $type = (string) ($payload['type'] ?? '');
        if ($type !== 'brands' && $type !== 'toplists') {
            return;
        }

        $error = (string) ($payload['error'] ?? 'unknown error');
        $page  = (int) ($payload['page'] ?? 0);

        $title  = ucfirst($type) . ' sync failed · page ' . $page;
        $detail = $this->truncate($error, 240);

        $this->push([
            'ts'     => time(),
            'status' => 'error',
            'title'  => $title,
            'detail' => $detail,
            'source' => $type,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit = 5): array
    {
        $all = $this->all();
        if ($limit <= 0) {
            return [];
        }
        return array_slice($all, 0, $limit);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $stored = \get_option(self::OPTION_KEY, []);
        return is_array($stored) ? array_values($stored) : [];
    }

    public function clear(): void
    {
        \update_option(self::OPTION_KEY, [], false);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function push(array $entry): void
    {
        $all = $this->all();
        array_unshift($all, $entry);
        if (count($all) > self::MAX_ENTRIES) {
            $all = array_slice($all, 0, self::MAX_ENTRIES);
        }
        // Autoload off — this option grows and is read only on the Dashboard.
        \update_option(self::OPTION_KEY, $all, false);
    }

    private function truncate(string $text, int $max): string
    {
        if (strlen($text) <= $max) {
            return $text;
        }
        return substr($text, 0, $max - 1) . '…';
    }
}
