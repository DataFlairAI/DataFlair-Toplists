<?php
/**
 * API Contract Safety — the DATABASE SCHEMA SNAPSHOT.
 *
 * The plugin's custom tables are a public contract, not a private detail.
 * Tenants install this plugin purely as a sync engine and render from their
 * own theme code, reading `wp_dataflair_toplists` / `wp_dataflair_brands`
 * directly instead of using the shortcode or the Gutenberg block. Renaming
 * or removing a column silently breaks that code on their next plugin update,
 * with no notice and nothing in the sync layer able to catch it.
 *
 * So the same rule the DataFlair API follows applies here: ADDITIVE ONLY.
 * Adding a column is safe (update this snapshot in the same PR and add an
 * UPGRADING.md note). Renaming, removing, or repurposing one is BREAKING and
 * needs a documented migration in UPGRADING.md plus a major version bump.
 *
 * The snapshot covers the columns a live table actually ends up with, which
 * is the union of EVERY `CREATE TABLE` block for that table (there are three
 * install paths) plus every `ALTER TABLE ... ADD COLUMN` upgrade. Pinning
 * only the first CREATE block would miss columns that exist on every real
 * install and give false confidence.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;

final class DatabaseSchemaContractTest extends TestCase
{
    private const MIGRATOR = 'src/Database/SchemaMigrator.php';

    private const HINT = "\n\nAdded a column? Update this snapshot in the same PR and add an UPGRADING.md note."
        . "\nRenamed or removed one? That is BREAKING for tenants reading these tables directly:"
        . "\nkeep the old column, or ship a documented migration and a major version bump.";

    /**
     * SQL-string variables, ALTER variables, and generated-column methods
     * that build each table. Generated columns are added as
     * `ADD COLUMN $column` from a name => json-path map, so their names have
     * to be read from that map rather than from the SQL.
     */
    private const SOURCES = [
        'toplists' => [
            'sql'       => ['toplists_sql', 'sql'],
            'alter'     => ['table_name'],
            'generated' => ['ensureToplistsGeoVirtualColumns'],
        ],
        'brands' => [
            'sql'       => ['brands_sql'],
            'alter'     => ['brands_table_name', 'brands_table'],
            'generated' => [],
        ],
        'alts' => [
            'sql'       => ['alternatives_sql'],
            'alter'     => [],
            'generated' => [],
        ],
    ];

    private function source(): string
    {
        return (string) file_get_contents(DATAFLAIR_PLUGIN_DIR . self::MIGRATOR);
    }

    /**
     * Every column a live copy of this table ends up with.
     *
     * @return array<int, string>
     */
    private function liveColumns(string $table): array
    {
        $source  = $this->source();
        $columns = [];
        $blocks  = 0;

        foreach (self::SOURCES[$table]['sql'] as $variable) {
            $pattern = '/\$' . preg_quote($variable, '/')
                . '\s*=\s*"CREATE TABLE IF NOT EXISTS[^(]*\((.*?)\)\s*\$charset_collate;/s';
            if (!preg_match_all($pattern, $source, $matches)) {
                continue;
            }
            foreach ($matches[1] as $body) {
                $blocks++;
                foreach (explode("\n", $body) as $line) {
                    $line = trim($line);
                    if ($line === '' || preg_match('/^(PRIMARY\s+KEY|UNIQUE\s+KEY|KEY|INDEX)\b/i', $line)) {
                        continue;
                    }
                    if (preg_match('/^`?([a-z_][a-z0-9_]*)`?\s+/i', $line, $col)) {
                        $columns[] = strtolower($col[1]);
                    }
                }
            }
        }

        $this->assertGreaterThan(0, $blocks, "No CREATE TABLE block found for {$table} in " . self::MIGRATOR);

        foreach (self::SOURCES[$table]['alter'] as $variable) {
            $pattern = '/ALTER TABLE \$' . preg_quote($variable, '/') . '\s+ADD COLUMN\s+`?([a-z_][a-z0-9_]*)`?/is';
            if (preg_match_all($pattern, $source, $matches)) {
                foreach ($matches[1] as $col) {
                    $columns[] = strtolower($col);
                }
            }
        }

        foreach (self::SOURCES[$table]['generated'] as $method) {
            $body = $this->methodBody($source, $method);
            if (preg_match_all("/'([a-z_][a-z0-9_]*)'\s*=>\s*'\\\$\./i", $body, $matches)) {
                foreach ($matches[1] as $col) {
                    $columns[] = strtolower($col);
                }
            }
        }

        return array_values(array_unique($columns));
    }

    /** Source text of one method, from its signature to the next one. */
    private function methodBody(string $source, string $method): string
    {
        $start = strpos($source, 'function ' . $method . '(');
        $this->assertNotFalse($start, "Method {$method}() not found in " . self::MIGRATOR);

        $next = strpos($source, "\n    private function ", $start + 1);
        $next = $next === false ? strlen($source) : $next;

        return substr($source, $start, $next - $start);
    }

    public function test_toplists_table_columns_match_the_locked_contract(): void
    {
        $this->assertEqualsCanonicalizing([
            'id',
            'api_toplist_id',
            'name',
            'slug',
            'current_period',
            'published_at',
            'item_count',
            'locked_count',
            'sync_warnings',
            'data',
            'version',
            'last_synced',
            // Generated columns added by the geo index migration; queried by
            // name in ToplistsRepository.
            'list_template_id_virtual',
            'geo_type_virtual',
            'geo_code_virtual',
        ], $this->liveColumns('toplists'), 'wp_dataflair_toplists' . self::HINT);
    }

    public function test_brands_table_columns_match_the_locked_contract(): void
    {
        $this->assertEqualsCanonicalizing([
            'id',
            'api_brand_id',
            'name',
            'slug',
            'status',
            'product_types',
            'licenses',
            'top_geos',
            'offers_count',
            'trackers_count',
            'classification_types',
            'review_url_override',
            'is_disabled',
            'data',
            'last_synced',
            // Render-critical, added by the v1.10 upgrade and read by name in
            // BrandMetaPrefetcher; absent from the createTables() block.
            'local_logo_url',
            'cached_review_post_id',
            'external_id_virtual',
        ], $this->liveColumns('brands'), 'wp_dataflair_brands' . self::HINT);
    }

    public function test_alternative_toplists_table_columns_match_the_locked_contract(): void
    {
        $this->assertEqualsCanonicalizing([
            'id',
            'toplist_id',
            'geo',
            'alternative_toplist_id',
            'created_at',
            'updated_at',
        ], $this->liveColumns('alts'), 'wp_dataflair_alternative_toplists' . self::HINT);
    }

    public function test_every_create_block_for_a_table_declares_the_same_columns(): void
    {
        // Three install paths build the brands table. If they drift, sites
        // that file-deploy get a different shape from sites that upgrade.
        $source = $this->source();
        preg_match_all(
            '/\$brands_sql\s*=\s*"CREATE TABLE IF NOT EXISTS[^(]*\((.*?)\)\s*\$charset_collate;/s',
            $source,
            $matches
        );
        $this->assertGreaterThanOrEqual(2, count($matches[1]), 'expected multiple brands CREATE blocks');

        $sets = [];
        foreach ($matches[1] as $body) {
            $cols = [];
            foreach (explode("\n", $body) as $line) {
                $line = trim($line);
                if ($line === '' || preg_match('/^(PRIMARY\s+KEY|UNIQUE\s+KEY|KEY|INDEX)\b/i', $line)) {
                    continue;
                }
                if (preg_match('/^`?([a-z_][a-z0-9_]*)`?\s+/i', $line, $col)) {
                    $cols[] = strtolower($col[1]);
                }
            }
            sort($cols);
            $sets[] = $cols;
        }

        // Known, accepted divergence: createTables() omits the two v1.10
        // columns that upgradeDatabase() adds by ALTER on every install.
        $accepted = ['cached_review_post_id', 'local_logo_url'];
        $union    = array_unique(array_merge(...$sets));
        foreach ($sets as $i => $cols) {
            $missing = array_values(array_diff($union, $cols, $accepted));
            $this->assertSame(
                [],
                $missing,
                "brands CREATE block #{$i} is missing columns another block declares" . self::HINT
            );
        }
    }

    public function test_the_data_column_stores_the_payload_the_api_returned(): void
    {
        // Tenants parse this column directly. Each writer must persist the
        // API's own object rather than a reshaped one.
        $store = (string) file_get_contents(DATAFLAIR_PLUGIN_DIR . 'src/Database/ToplistDataStore.php');
        $this->assertMatchesRegularExpression(
            "/'data'\s*=>\s*\\\$rawJson,/",
            $store,
            'ToplistDataStore must store the verbatim response body.' . self::HINT
        );

        $sync = (string) file_get_contents(DATAFLAIR_PLUGIN_DIR . 'src/Sync/ToplistSyncService.php');
        $this->assertMatchesRegularExpression(
            "/wp_json_encode\(\['data'\s*=>\s*\\\$toplist\]\)/",
            $sync,
            'The bulk path must wrap each toplist as {"data": ...}, the same shape the show-endpoint returns.' . self::HINT
        );
    }
}
