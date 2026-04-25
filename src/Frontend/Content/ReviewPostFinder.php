<?php
/**
 * Phase 9.9 — Slug-tolerant review-CPT finder.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Frontend\Content;

use WP_Post;

/**
 * Find an existing review CPT when the post slug differs from the API brand
 * slug (e.g. live URL `/reviews/1xbet-sportsbook-india/` vs API brand slug
 * `1xbet-sportsbook`). Matches `_review_brand_id` post-meta to
 * `api_brand_id` / `id` from the brand payload — the same idea as the
 * render-casino-card pros fallback.
 *
 * Direct SQL is intentional: it bypasses third-party `pre_get_posts` and
 * `meta_query` quirks and matches both string and numeric meta_value rows.
 */
class ReviewPostFinder
{
    /**
     * @param array<string,mixed> $brand
     */
    public function findByBrandMeta(array $brand): ?WP_Post
    {
        global $wpdb;

        $ids = [];
        foreach (['api_brand_id', 'id'] as $key) {
            if (!empty($brand[$key])) {
                $v = (int) $brand[$key];
                if ($v > 0) {
                    $ids[$v] = true;
                }
            }
        }
        if (empty($ids)) {
            return null;
        }

        $bid_list = array_keys($ids);
        $in_placeholders = implode(',', array_fill(0, count($bid_list), '%s'));
        $sql = "SELECT p.ID, p.post_status FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_review_brand_id'
            WHERE p.post_type = 'review'
            AND p.post_status IN ('publish','draft','pending','future','private')
            AND pm.meta_value IN ($in_placeholders)
            ORDER BY p.post_modified DESC";

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...array_map('strval', $bid_list)));

        if (empty($rows) && count($bid_list) === 1) {
            // Some sites store _review_brand_id as integer-ish without strict string match.
            $one = (int) $bid_list[0];
            $sql2 = "SELECT p.ID, p.post_status FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_review_brand_id'
                WHERE p.post_type = 'review'
                AND p.post_status IN ('publish','draft','pending','future','private')
                AND CAST(pm.meta_value AS UNSIGNED) = %d
                ORDER BY p.post_modified DESC";
            $rows = $wpdb->get_results($wpdb->prepare($sql2, $one));
        }

        if (empty($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            if ('publish' === $row->post_status) {
                return get_post((int) $row->ID);
            }
        }

        return get_post((int) $rows[0]->ID);
    }
}
