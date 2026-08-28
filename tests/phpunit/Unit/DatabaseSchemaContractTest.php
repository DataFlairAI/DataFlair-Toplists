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
 * This pins the column names at the source, so a rename fails here rather
 * than on a tenant's site.
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
     * Column names declared in one `CREATE TABLE` block of SchemaMigrator.
     *
     * @return array<int, string>
     */
    private function columnsOf(string $sqlVariable): array
    {
        $source = (string) file_get_contents(DATAFLAIR_PLUGIN_DIR . self::MIGRATOR);

        $pattern = '/\$' . preg_quote($sqlVariable, '/') . '\s*=\s*"CREATE TABLE IF NOT EXISTS[^(]*\((.*?)\)\s*\$charset_collate;/s';
        $this->assertSame(1, preg_match($pattern, $source, $m), "Could not locate \${$sqlVariable} in " . self::MIGRATOR);

        $columns = [];
        foreach (explode("\n", $m[1]) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // Skip index/key declarations; they are not part of the read contract.
            if (preg_match('/^(PRIMARY\s+KEY|UNIQUE\s+KEY|KEY|INDEX)\b/i', $line)) {
                continue;
            }
            if (preg_match('/^([a-z_][a-z0-9_]*)\s+/i', $line, $col)) {
                $columns[] = strtolower($col[1]);
            }
        }

        return $columns;
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
        ], $this->columnsOf('toplists_sql'), 'wp_dataflair_toplists' . self::HINT);
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
        ], $this->columnsOf('brands_sql'), 'wp_dataflair_brands' . self::HINT);
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
        ], $this->columnsOf('alternatives_sql'), 'wp_dataflair_alternative_toplists' . self::HINT);
    }

    public function test_data_column_still_stores_the_verbatim_api_payload(): void
    {
        // Tenants parse this column directly. If the store ever begins writing
        // a transformed shape instead of the raw response, every one of those
        // integrations breaks silently.
        $store = (string) file_get_contents(DATAFLAIR_PLUGIN_DIR . 'src/Database/ToplistDataStore.php');

        $this->assertStringContainsString(
            "'data'           => \$rawJson,",
            $store,
            'The data column must keep storing the verbatim API payload.' . self::HINT
        );
    }
}
