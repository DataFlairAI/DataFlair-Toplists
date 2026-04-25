<?php
/**
 * Phase 9.10 — Chunked transient cleaner.
 *
 * Pre-Phase-0B `clear_tracker_transients()` issued a single unbounded
 * `DELETE FROM wp_options WHERE option_name LIKE '_transient_dataflair_tracker_%'`
 * which on Sigma's option table (100k+ rows) hit max_allowed_packet,
 * blew binlog row-size, and could deadlock under row-based replication.
 *
 * Phase 0B H10 rewrote it as a chunked loop with `LIMIT 1000` per
 * statement and an optional WallClockBudget so an AJAX driver can yield
 * back when it's about to exceed its timeout. Phase 9.10 extracts the
 * loop verbatim into this class — bytes for byte the same SQL, the same
 * patterns, the same chunk size.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Sync;

use DataFlair\Toplists\Support\WallClockBudget;

final class TransientCleaner
{
    private const CHUNK = 1000;

    /**
     * Delete every accumulated `dataflair_tracker_*` transient row from
     * `wp_options`. Returns the total row count deleted across all
     * chunks.
     */
    public function clear(?WallClockBudget $budget = null): int
    {
        global $wpdb;
        $total = 0;

        foreach (
            [
                '_transient_dataflair_tracker_%',
                '_transient_timeout_dataflair_tracker_%',
            ] as $pattern
        ) {
            while (true) {
                if ($budget !== null && $budget->exceeded(1.0)) {
                    break;
                }
                $deleted = $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$wpdb->options}
                         WHERE option_name LIKE %s
                         ORDER BY option_id
                         LIMIT %d",
                        $pattern,
                        self::CHUNK
                    )
                );
                if ($deleted === false) {
                    break;
                }
                $total += (int) $deleted;
                if ((int) $deleted < self::CHUNK) {
                    break;
                }
            }
        }

        error_log('DataFlair: Cleared ' . $total . ' tracker transient rows before sync');
        return $total;
    }
}
