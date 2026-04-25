<?php
/**
 * Phase 9.10 — Paginated table wipe (replaces TRUNCATE).
 *
 * TRUNCATE is implicitly committed (cannot be rolled back), cannot be
 * replicated under STATEMENT-based binlog, and on some managed MySQL
 * hosts it forces a metadata lock that blocks concurrent reads for
 * seconds. A chunked DELETE is binlog-safe, cancellable, and stays
 * inside the MySQL packet size even on multi-million-row tables.
 *
 * Phase 0B H11 introduced the helper; Phase 9.10 extracts it verbatim.
 *
 * Hardens against SQL injection by whitelisting the table against the
 * plugin's known prefix — MySQL doesn't allow placeholders in the
 * identifier position, so we cannot pass the table name through
 * `$wpdb->prepare()` and have to enforce the whitelist in PHP.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Database;

final class PaginatedDeleter
{
    /**
     * Delete every row from a whitelisted plugin table in chunks of
     * `$chunk` rows per statement. Returns the total deleted count.
     *
     * @param string $table Fully-qualified table name (e.g. `wp_dataflair_toplists`).
     * @param int    $chunk Rows to delete per statement (clamped 50..5000).
     */
    public function deleteAll(string $table, int $chunk = 500): int
    {
        global $wpdb;

        $allowed = [
            $wpdb->prefix . DATAFLAIR_TABLE_NAME,
            $wpdb->prefix . DATAFLAIR_BRANDS_TABLE_NAME,
            $wpdb->prefix . DATAFLAIR_ALTERNATIVE_TOPLISTS_TABLE_NAME,
        ];
        if (!in_array($table, $allowed, true)) {
            return 0;
        }

        $chunk = max(50, min(5000, $chunk));
        $total = 0;
        while (true) {
            $deleted = $wpdb->query(
                $wpdb->prepare("DELETE FROM $table LIMIT %d", $chunk)
            );
            if ($deleted === false) {
                break;
            }
            $total += (int) $deleted;
            if ((int) $deleted < $chunk) {
                break;
            }
        }
        return $total;
    }
}
