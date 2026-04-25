<?php
/**
 * Phase 9.6 (admin UX redesign) — capped FIFO history of sync batches.
 *
 * Listens to `dataflair_sync_batch_finished` and accumulates per-page counts
 * in a transient. When the payload includes `is_complete = true` (or the first
 * page of a new batch starts) it writes a single batch-level summary entry to
 * the `dataflair_sync_history` option — one row per full sync run, not per page.
 *
 * Also listens to `dataflair_sync_item_failed` to capture hard errors that
 * never reach a completion event.
 *
 * Storage shape (each entry):
 *   [
 *     'ts'     => int unix timestamp (UTC),
 *     'status' => 'success' | 'partial' | 'error',
 *     'title'  => human-readable headline ("Brands sync — 842 synced · 7 pages"),
 *     'detail' => secondary line ("0 errors · 42.1s"),
 *     'source' => 'brands' | 'toplists',
 *   ]
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Sync;

final class SyncHistoryRecorder
{
    public const OPTION_KEY  = 'dataflair_sync_history';
    public const MAX_ENTRIES = 50;

    /** Transient TTL — 2 hours. Long enough to survive a slow multi-page sync. */
    private const ACC_TTL = 7200;

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
        $type = (string) ($payload['type'] ?? '');
        if ($type !== 'brands' && $type !== 'toplists') {
            return;
        }

        $page       = (int) ($payload['page'] ?? 1);
        $itemsDone  = (int) ($payload['items_done'] ?? 0);
        $errors     = (int) ($payload['errors'] ?? 0);
        $elapsed    = (float) ($payload['elapsed_seconds'] ?? 0.0);
        $partial    = !empty($payload['partial']);
        $isComplete = !empty($payload['is_complete']);

        $accKey = 'dataflair_sync_acc_' . $type;

        // On first page, start a fresh accumulator.
        if ($page === 1) {
            $acc = [
                'ts'      => time(),
                'synced'  => 0,
                'errors'  => 0,
                'elapsed' => 0.0,
                'pages'   => 0,
            ];
        } else {
            $stored = \get_transient($accKey);
            $acc    = is_array($stored) ? $stored : [
                'ts'      => time(),
                'synced'  => 0,
                'errors'  => 0,
                'elapsed' => 0.0,
                'pages'   => 0,
            ];
        }

        $acc['synced']  += $itemsDone;
        $acc['errors']  += $errors;
        $acc['elapsed'] += $elapsed;
        $acc['pages']   += 1;

        if ($isComplete || $partial) {
            // Flush to history as a single batch entry.
            $status = ($acc['errors'] > 0 || $partial) ? 'partial' : 'success';
            $label  = ucfirst($type) . ' sync';
            $pages  = $acc['pages'];
            $title  = $label . ' — ' . $acc['synced'] . ' synced · ' . $pages . ' page' . ($pages === 1 ? '' : 's');
            $detail = sprintf(
                '%d error%s · %ss',
                $acc['errors'],
                $acc['errors'] === 1 ? '' : 's',
                number_format($acc['elapsed'], 1)
            );
            $this->push([
                'ts'     => (int) $acc['ts'],
                'status' => $status,
                'title'  => $title,
                'detail' => $detail,
                'source' => $type,
            ]);
            \delete_transient($accKey);
        } else {
            // Not done yet — persist accumulator until next page arrives.
            \set_transient($accKey, $acc, self::ACC_TTL);
        }
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

        $error  = (string) ($payload['error'] ?? 'unknown error');
        $page   = (int) ($payload['page'] ?? 0);
        $title  = ucfirst($type) . ' sync failed · page ' . $page;
        $detail = $this->truncate($error, 240);

        // Also clear any in-progress accumulator so the next run starts fresh.
        \delete_transient('dataflair_sync_acc_' . $type);

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
