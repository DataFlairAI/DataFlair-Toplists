<?php
/**
 * Concrete toplist repository backed by `$wpdb`.
 *
 * Phase 2 — sync + render paths route through here. Admin-page queries
 * (pagination, filter distinct-values) stay in the god-class until Phase 5.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Database;

final class ToplistsRepository implements ToplistsRepositoryInterface
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
        $this->table = $this->wpdb->prefix . DATAFLAIR_TABLE_NAME;
    }

    public function findByApiToplistId(int $api_toplist_id): ?array
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE api_toplist_id = %d LIMIT 1",
                $api_toplist_id
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

    public function upsert(array $row)
    {
        $api_toplist_id = isset($row['api_toplist_id']) ? (int) $row['api_toplist_id'] : 0;
        if ($api_toplist_id <= 0) {
            return false;
        }

        $existing = $this->findByApiToplistId($api_toplist_id);
        if ($existing !== null) {
            $result = $this->wpdb->update(
                $this->table,
                $row,
                ['api_toplist_id' => $api_toplist_id]
            );
            return $result === false ? false : (int) $existing['id'];
        }

        $result = $this->wpdb->insert($this->table, $row);
        return $result === false ? false : (int) $this->wpdb->insert_id;
    }

    public function deleteByApiToplistId(int $api_toplist_id): bool
    {
        $result = $this->wpdb->delete($this->table, ['api_toplist_id' => $api_toplist_id]);
        return $result !== false;
    }

    public function collectGeoNames(): array
    {
        $rows = $this->wpdb->get_results("SELECT data FROM {$this->table}", ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        $geos = [];
        foreach ($rows as $row) {
            $payload = json_decode((string) ($row['data'] ?? ''), true);
            $name    = $payload['data']['geo']['name'] ?? null;
            if (is_string($name) && $name !== '' && !in_array($name, $geos, true)) {
                $geos[] = $name;
            }
        }

        sort($geos);
        return $geos;
    }

    public function listAllForOptions(): array
    {
        $rows = $this->wpdb->get_results(
            "SELECT api_toplist_id, name, slug FROM {$this->table} ORDER BY api_toplist_id ASC",
            ARRAY_A
        );
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'api_toplist_id' => (int) ($row['api_toplist_id'] ?? 0),
                'name'           => (string) ($row['name'] ?? ''),
                'slug'           => (string) ($row['slug'] ?? ''),
            ];
        }
        return $out;
    }

    public function countAll(): int
    {
        $count = $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->table}");
        return (int) $count;
    }

    public function findPaginated(ToplistsQuery $query): ToplistsPage
    {
        $where  = '';
        $params = [];

        if ($query->search !== '') {
            $like   = '%' . $this->wpdb->esc_like($query->search) . '%';
            $where  = 'WHERE (name LIKE %s OR slug LIKE %s)';
            $params = [$like, $like];
        }

        $order  = esc_sql($query->sortBy) . ' ' . $query->sortDir;
        $offset = ($query->page - 1) * $query->perPage;

        $count_sql = "SELECT COUNT(*) FROM {$this->table} {$where}";
        $total = $where !== ''
            ? (int) $this->wpdb->get_var($this->wpdb->prepare($count_sql, ...$params))
            : (int) $this->wpdb->get_var($count_sql);

        $select_cols = 'id, api_toplist_id, name, slug, version, last_synced, item_count, sync_warnings, current_period,
            JSON_UNQUOTE(JSON_EXTRACT(data, \'$.data.template.name\')) AS template_name';
        $data_sql = "SELECT {$select_cols} FROM {$this->table} {$where} ORDER BY {$order} LIMIT %d OFFSET %d";
        $data_params = [...$params, $query->perPage, $offset];
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare($data_sql, ...$data_params),
            ARRAY_A
        );

        return new ToplistsPage(
            rows:    is_array($rows) ? $rows : [],
            total:   $total,
            page:    $query->page,
            perPage: $query->perPage
        );
    }

    public function findItemSummaryByApiToplistId(int $api_toplist_id): array
    {
        $row = $this->findByApiToplistId($api_toplist_id);
        if ($row === null) {
            return [];
        }

        $payload = json_decode((string) ($row['data'] ?? ''), true);
        $raw_items = $payload['data']['items'] ?? ($payload['items'] ?? []);
        if (!is_array($raw_items)) {
            return [];
        }

        $out = [];
        foreach ($raw_items as $item) {
            // API stores items as {brand:{id,name}, offer:{offerText}, position}
            $brand_id   = (int) ($item['brand']['id'] ?? $item['brand_id'] ?? $item['casino_id'] ?? 0);
            $brand_name = (string) ($item['brand']['name'] ?? '');
            $bonus      = (string) ($item['offer']['offerText'] ?? $item['offer']['bonus_text'] ??
                           $item['bonus_offer'] ?? $item['bonus_text'] ?? $item['bonus_code'] ?? '');
            $code       = (string) ($item['offer']['bonus_code'] ?? $item['bonus_code'] ?? '');
            $position   = (int) ($item['position'] ?? ($item['rank'] ?? 0));
            $out[] = [
                'position'    => $position,
                'brand_id'    => $brand_id,
                'brand_name'  => $brand_name,
                'bonus_offer' => $bonus,
                'bonus_code'  => $code,
            ];
        }

        usort($out, static fn(array $a, array $b) => $a['position'] <=> $b['position']);
        return $out;
    }

    public function findRawDataByApiToplistId(int $api_toplist_id): ?array
    {
        $row = $this->findByApiToplistId($api_toplist_id);
        if ($row === null) {
            return null;
        }
        $decoded = json_decode((string) ($row['data'] ?? ''), true);
        return is_array($decoded) ? $decoded : null;
    }
}
