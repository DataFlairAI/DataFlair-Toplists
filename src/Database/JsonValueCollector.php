<?php
/**
 * Phase 9.10 — Distinct CSV-column value collector.
 *
 * Phase 0B H5 added this helper to populate brand-table filter dropdowns
 * (licenses, top_geos, product_types) from a single `SELECT DISTINCT`
 * against the lean CSV column on `wp_dataflair_brands` instead of
 * deserialising every row's JSON `data` blob in PHP.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Database;

final class JsonValueCollector
{
    /**
     * Returns the sorted, de-duplicated set of trimmed values that
     * appear across every row's CSV `$column` on `$brandsTable`.
     *
     * Column whitelist matches Phase 0B H5 — only the three lean CSV
     * columns are accepted; anything else returns an empty array.
     *
     * @return string[]
     */
    public function collect(string $brandsTable, string $column): array
    {
        global $wpdb;
        $allowed = ['licenses', 'top_geos', 'product_types'];
        if (!in_array($column, $allowed, true)) {
            return [];
        }
        $rows = $wpdb->get_col(
            "SELECT DISTINCT $column FROM $brandsTable WHERE $column IS NOT NULL AND $column != ''"
        );
        $values = [];
        foreach ($rows as $csv) {
            foreach (array_map('trim', explode(',', (string) $csv)) as $v) {
                if ($v !== '') {
                    $values[$v] = true;
                }
            }
        }
        $values = array_keys($values);
        sort($values);
        return $values;
    }
}
