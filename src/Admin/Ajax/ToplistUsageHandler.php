<?php
/**
 * Phase 9.6 (admin UX redesign) — Count pages/posts that use a DataFlair shortcode.
 *
 * Scans post_content for `[dataflair_toplist` (both spellings used historically).
 * Cached in transient `dataflair_toplist_usage` for 1 hour to avoid repeated
 * full-table scans on the Dashboard.
 *
 * Output: { count: int, post_ids: int[] }
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Ajax;

use DataFlair\Toplists\Admin\AjaxHandlerInterface;

final class ToplistUsageHandler implements AjaxHandlerInterface
{
    private const TRANSIENT = 'dataflair_toplist_usage';
    private const TTL       = HOUR_IN_SECONDS;

    public function handle(array $request): array
    {
        $cached = get_transient(self::TRANSIENT);
        if (is_array($cached)) {
            return ['success' => true, 'data' => $cached];
        }

        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_status IN ('publish','draft','pending','private')
               AND post_content LIKE '%[dataflair_toplist%'",
            ARRAY_A
        );

        $post_ids = is_array($rows) ? array_map(static fn(array $r) => (int) $r['ID'], $rows) : [];
        $data     = ['count' => count($post_ids), 'post_ids' => $post_ids];

        set_transient(self::TRANSIENT, $data, self::TTL);
        return ['success' => true, 'data' => $data];
    }
}
