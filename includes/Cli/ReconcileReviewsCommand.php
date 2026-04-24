<?php
/**
 * WP-CLI command: `wp dataflair reconcile-reviews [--batch=<n>] [--dry-run]`
 *
 * Phase 0A H0 support command. Render is now read-only: it reads a
 * pre-computed `cached_review_post_id` column on wp_dataflair_brands instead
 * of calling `get_or_create_review_post()` at render time. This command
 * backfills that column for existing rows (and any future rows whose
 * review CPT was created out-of-band).
 *
 * Behaviour:
 *   - Scans `wp_dataflair_brands` for rows where `cached_review_post_id IS NULL`
 *     and `status = 'Active'`.
 *   - For each row, looks up a published `review` post by (a) `_review_brand_id`
 *     meta matching the brand id, then (b) `post_name` matching the brand slug.
 *   - Updates the column when a match is found.
 *
 * Flags:
 *   --batch=<n>   Rows processed per iteration (default 500). The loop
 *                 self-drains in real mode; in `--dry-run` mode it paginates
 *                 via OFFSET since nothing is written back.
 *   --dry-run     Reports what would be linked without writing.
 *
 * This command is intentionally small, read-mostly, and idempotent. Running
 * it twice on the same dataset is a no-op after the first pass.
 */
declare(strict_types=1);

namespace DataFlair\Toplists\Cli;

class ReconcileReviewsCommand
{
    /**
     * @param array<int, string>           $args
     * @param array<string, string|bool>   $assoc_args
     */
    public function __invoke(array $args, array $assoc_args): void
    {
        global $wpdb;

        $batch_size = isset($assoc_args['batch']) ? max(1, (int) $assoc_args['batch']) : 500;
        $dry_run    = !empty($assoc_args['dry-run']);

        $brands_table = $wpdb->prefix . 'dataflair_brands';

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$brands_table} "
            . "WHERE cached_review_post_id IS NULL AND status = 'Active'"
        );

        \WP_CLI::log(sprintf(
            '[dataflair] reconcile-reviews: %d candidate brand(s)%s (batch=%d)',
            $total,
            $dry_run ? ' — DRY RUN' : '',
            $batch_size
        ));

        if ($total === 0) {
            \WP_CLI::success('Nothing to reconcile.');
            return;
        }

        $processed = 0;
        $linked    = 0;
        $offset    = 0;

        while (true) {
            if ($dry_run) {
                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT id, api_brand_id, name, slug FROM {$brands_table} "
                        . "WHERE cached_review_post_id IS NULL AND status = 'Active' "
                        . "ORDER BY id ASC LIMIT %d OFFSET %d",
                        $batch_size,
                        $offset
                    )
                );
            } else {
                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT id, api_brand_id, name, slug FROM {$brands_table} "
                        . "WHERE cached_review_post_id IS NULL AND status = 'Active' "
                        . "ORDER BY id ASC LIMIT %d",
                        $batch_size
                    )
                );
            }

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $processed++;
                $review_post_id = $this->find_review_post_id_for_brand($row);

                if ($review_post_id === null) {
                    continue;
                }

                $linked++;

                if ($dry_run) {
                    \WP_CLI::log(sprintf(
                        '  [dry-run] brand #%d (%s) → review post #%d',
                        (int) $row->id,
                        $row->slug,
                        $review_post_id
                    ));
                    continue;
                }

                $wpdb->update(
                    $brands_table,
                    ['cached_review_post_id' => $review_post_id],
                    ['id' => (int) $row->id],
                    ['%d'],
                    ['%d']
                );

                \WP_CLI::log(sprintf(
                    '  linked brand #%d (%s) → review post #%d',
                    (int) $row->id,
                    $row->slug,
                    $review_post_id
                ));
            }

            if ($dry_run) {
                $offset += $batch_size;
                if ($offset >= $total) {
                    break;
                }
            }
        }

        \WP_CLI::success(sprintf(
            '%s %d of %d candidate brand(s).',
            $dry_run ? 'Would link' : 'Linked',
            $linked,
            $processed
        ));
    }

    /**
     * Resolve the published `review` post id for a brand row.
     * Priority:
     *   1. `_review_brand_id` post meta matching the brand's id or api_brand_id.
     *   2. `post_name` equal to the brand slug.
     * Returns null when no published match exists.
     */
    private function find_review_post_id_for_brand(object $row): ?int
    {
        global $wpdb;

        if (!post_type_exists('review')) {
            return null;
        }

        $brand_id_candidates = [];
        if (isset($row->id)) {
            $brand_id_candidates[] = (int) $row->id;
        }
        if (isset($row->api_brand_id)) {
            $brand_id_candidates[] = (int) $row->api_brand_id;
        }
        $brand_id_candidates = array_values(array_unique(array_filter($brand_id_candidates)));

        if (!empty($brand_id_candidates)) {
            $placeholders = implode(',', array_fill(0, count($brand_id_candidates), '%d'));
            $sql = $wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p "
                . "INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID "
                . "WHERE p.post_type = 'review' AND p.post_status = 'publish' "
                . "AND pm.meta_key = '_review_brand_id' AND pm.meta_value IN ({$placeholders}) "
                . "ORDER BY p.ID ASC LIMIT 1",
                ...$brand_id_candidates
            );
            $post_id = (int) $wpdb->get_var($sql);
            if ($post_id > 0) {
                return $post_id;
            }
        }

        if (!empty($row->slug)) {
            $post = get_page_by_path($row->slug, OBJECT, 'review');
            if ($post && $post->post_status === 'publish') {
                return (int) $post->ID;
            }
        }

        return null;
    }
}
