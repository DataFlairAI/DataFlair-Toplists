<?php
/**
 * Concrete alternative-toplist repository backed by `$wpdb`.
 *
 * Phase 2 — sync + geo-aware render paths route through here.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Database;

final class AlternativesRepository implements AlternativesRepositoryInterface
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
        $this->table = $this->wpdb->prefix . DATAFLAIR_ALTERNATIVE_TOPLISTS_TABLE_NAME;
    }

    public function findByToplistId(int $toplist_id): array
    {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE toplist_id = %d",
                $toplist_id
            ),
            ARRAY_A
        );
        return is_array($rows) ? $rows : [];
    }

    public function findByToplistAndGeo(int $toplist_id, string $geo): ?array
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE toplist_id = %d AND geo = %s LIMIT 1",
                $toplist_id,
                $geo
            ),
            ARRAY_A
        );
        return is_array($row) ? $row : null;
    }

    public function upsert(array $row)
    {
        $toplist_id = isset($row['toplist_id']) ? (int) $row['toplist_id'] : 0;
        $geo        = isset($row['geo']) ? (string) $row['geo'] : '';
        if ($toplist_id <= 0 || $geo === '') {
            return false;
        }

        $existing = $this->findByToplistAndGeo($toplist_id, $geo);
        if ($existing !== null) {
            $result = $this->wpdb->update(
                $this->table,
                $row,
                ['toplist_id' => $toplist_id, 'geo' => $geo]
            );
            return $result === false ? false : (int) $existing['id'];
        }

        $result = $this->wpdb->insert($this->table, $row);
        return $result === false ? false : (int) $this->wpdb->insert_id;
    }

    public function deleteByToplistId(int $toplist_id): bool
    {
        $result = $this->wpdb->delete($this->table, ['toplist_id' => $toplist_id]);
        return $result !== false;
    }
}
