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

    public function findByName(string $name): ?array
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE name = %s LIMIT 1",
                $name
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

    public function updateReviewUrlOverrideByApiBrandId(int $api_brand_id, ?string $url): bool
    {
        $result = $this->wpdb->update(
            $this->table,
            ['review_url_override' => $url !== null && $url !== '' ? $url : null],
            ['api_brand_id' => $api_brand_id],
            ['%s'],
            ['%d']
        );
        return $result !== false;
    }

    public function setDisabledByApiBrandIds(array $api_brand_ids, bool $disabled): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $api_brand_ids))));
        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $flag = $disabled ? 1 : 0;

        $sql = $this->wpdb->prepare(
            "UPDATE {$this->table} SET is_disabled = %d WHERE api_brand_id IN ($placeholders)",
            ...array_merge([$flag], $ids)
        );
        $affected = $this->wpdb->query($sql);
        return $affected === false ? 0 : (int) $affected;
    }

    public function findPaginated(BrandsQuery $query): BrandsPage
    {
        [$where, $params] = $this->buildWhereForQuery($query);

        $countSql = "SELECT COUNT(*) FROM {$this->table} {$where}";
        $total = (int) ($params === []
            ? $this->wpdb->get_var($countSql)
            : $this->wpdb->get_var($this->wpdb->prepare($countSql, ...$params)));

        // SECURITY: sortBy is whitelisted in BrandsQuery::normalizeSort().
        $orderBy = $query->sortBy . ' ' . $query->sortDir;

        $rowsSql = "SELECT * FROM {$this->table} {$where} ORDER BY {$orderBy} LIMIT %d OFFSET %d";
        $rowsParams = array_merge($params, [$query->perPage, $query->offset()]);
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare($rowsSql, ...$rowsParams),
            ARRAY_A
        );
        if (!is_array($rows)) {
            $rows = [];
        }

        return new BrandsPage($rows, $total, $query->page, $query->perPage);
    }

    public function findActiveByApiBrandIds(array $api_brand_ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $api_brand_ids))));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE is_disabled = 0 AND api_brand_id IN ($placeholders)",
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

    public function collectDistinctValuesForFilter(string $field): array
    {
        $csvCols = ['licenses', 'top_geos', 'product_types', 'classification_types'];
        if (in_array($field, $csvCols, true)) {
            $rows = $this->wpdb->get_col("SELECT {$field} FROM {$this->table} WHERE {$field} IS NOT NULL AND {$field} != ''");
            $values = [];
            foreach ((array) $rows as $row) {
                foreach (array_map('trim', explode(',', (string) $row)) as $v) {
                    if ($v !== '') {
                        $values[$v] = true;
                    }
                }
            }
            $out = array_keys($values);
            sort($out, SORT_NATURAL | SORT_FLAG_CASE);
            return $out;
        }

        if ($field === 'payments') {
            // Best-effort JSON extraction. The `data` blob shape varies across
            // upstream API versions, so we tolerate either `paymentMethods` or
            // `payments` arrays of strings or `{name}` objects.
            $blobs = $this->wpdb->get_col("SELECT data FROM {$this->table} WHERE data IS NOT NULL AND data != ''");
            $values = [];
            foreach ((array) $blobs as $blob) {
                $decoded = json_decode((string) $blob, true);
                if (!is_array($decoded)) {
                    continue;
                }
                foreach (['paymentMethods', 'payments', 'payment_methods'] as $key) {
                    if (!isset($decoded[$key]) || !is_array($decoded[$key])) {
                        continue;
                    }
                    foreach ($decoded[$key] as $entry) {
                        $name = is_array($entry) ? (string) ($entry['name'] ?? '') : (string) $entry;
                        $name = trim($name);
                        if ($name !== '') {
                            $values[$name] = true;
                        }
                    }
                }
            }
            $out = array_keys($values);
            sort($out, SORT_NATURAL | SORT_FLAG_CASE);
            return $out;
        }

        return [];
    }

    /**
     * Build the WHERE clause + bound parameters for {@see findPaginated()}.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function buildWhereForQuery(BrandsQuery $query): array
    {
        $clauses = [];
        $params = [];

        if ($query->search !== '') {
            $like = '%' . $this->wpdb->esc_like($query->search) . '%';
            $clauses[] = '(name LIKE %s OR slug LIKE %s OR CAST(api_brand_id AS CHAR) LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        foreach ([
            'licenses'             => $query->licenses,
            'top_geos'             => $query->geos,
            'product_types'        => $query->productTypes,
        ] as $col => $values) {
            if ($values === []) {
                continue;
            }
            $or = [];
            foreach ($values as $v) {
                $or[] = "{$col} LIKE %s";
                $params[] = '%' . $this->wpdb->esc_like($v) . '%';
            }
            $clauses[] = '(' . implode(' OR ', $or) . ')';
        }

        // Payments live in the JSON blob — match against serialized form.
        // Tolerates both `"name":"…"` objects and bare string entries.
        if ($query->payments !== []) {
            $or = [];
            foreach ($query->payments as $v) {
                $or[] = 'data LIKE %s';
                $params[] = '%' . $this->wpdb->esc_like($v) . '%';
            }
            $clauses[] = '(' . implode(' OR ', $or) . ')';
        }

        if ($query->disabled !== null) {
            $clauses[] = 'is_disabled = %d';
            $params[] = $query->disabled ? 1 : 0;
        }

        $where = $clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses);
        return [$where, $params];
    }
}
