<?php
/**
 * Concrete brand repository backed by `$wpdb`.
 *
 * Phase 2 — the god-class continues to own ad-hoc admin-page queries; the
 * key sync + render paths route through here. Every method is a thin shell
 * around one prepared statement, so the repository stays SQLite-friendly
 * for the existing test harness.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Database;

final class BrandsRepository implements BrandsRepositoryInterface
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
        $this->table = $this->wpdb->prefix . DATAFLAIR_BRANDS_TABLE_NAME;
    }

    public function findByApiBrandId(int $api_brand_id): ?array
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE api_brand_id = %d LIMIT 1",
                $api_brand_id
            ),
            ARRAY_A
        );
        return is_array($row) ? $row : null;
    }

    public function findBySlug(string $slug): ?array
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE slug = %s LIMIT 1",
                $slug
            ),
            ARRAY_A
        );
        return is_array($row) ? $row : null;
    }

    public function findManyByApiBrandIds(array $api_brand_ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $api_brand_ids))));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE api_brand_id IN ($placeholders)",
            ...$ids
        );
        $rows = $this->wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['api_brand_id']] = $r;
        }
        return $out;
    }

    public function findReviewPostsByApiBrandIds(array $api_brand_ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $api_brand_ids))));
        if ($ids === []) {
            return [];
        }

        $posts       = $this->wpdb->posts;
        $postmeta    = $this->wpdb->postmeta;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = $this->wpdb->prepare(
            "SELECT pm.meta_value AS api_brand_id, p.ID AS post_id
             FROM {$postmeta} pm
             INNER JOIN {$posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = 'dataflair_brand_id'
               AND p.post_status = 'publish'
               AND p.post_type = 'review'
               AND pm.meta_value IN ($placeholders)",
            ...$ids
        );
        $rows = $this->wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $api_id = (int) $r['api_brand_id'];
            $pid    = (int) $r['post_id'];
            // If a brand has multiple published reviews (rare), keep the lowest ID deterministically.
            if (!isset($out[$api_id]) || $out[$api_id] > $pid) {
                $out[$api_id] = $pid;
            }
        }
        return $out;
    }

    public function upsert(array $row)
    {
        $api_brand_id = isset($row['api_brand_id']) ? (int) $row['api_brand_id'] : 0;
        if ($api_brand_id <= 0) {
            return false;
        }

        $existing = $this->findByApiBrandId($api_brand_id);
        if ($existing !== null) {
            $result = $this->wpdb->update(
                $this->table,
                $row,
                ['api_brand_id' => $api_brand_id]
            );
            return $result === false ? false : (int) $existing['id'];
        }

        $result = $this->wpdb->insert($this->table, $row);
        return $result === false ? false : (int) $this->wpdb->insert_id;
    }

    public function updateLocalLogoUrl(int $id, string $local_url): bool
    {
        $result = $this->wpdb->update(
            $this->table,
            ['local_logo_url' => $local_url],
            ['id' => $id]
        );
        return $result !== false;
    }

    public function updateCachedReviewPostId(int $id, int $review_post_id): bool
    {
        $result = $this->wpdb->update(
            $this->table,
            ['cached_review_post_id' => $review_post_id],
            ['id' => $id]
        );
        return $result !== false;
    }
}
