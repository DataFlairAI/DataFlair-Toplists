<?php
/**
 * Integration tests for brand sync logic (tests 11-15).
 *
 * Verifies top_geos extraction, offers_count, upsert behavior,
 * and the geo warning log for brands with offers but no topGeos.
 *
 * Uses SQLite in-memory database — no live MySQL or WordPress required.
 */

use PHPUnit\Framework\TestCase;

class SyncBrandTest extends TestCase {

    private PDO    $pdo;
    private string $table = 'wp_dataflair_brands';
    private array  $error_log = [];

    protected function setUp(): void {
        parent::setUp();
        $this->error_log = [];
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("
            CREATE TABLE {$this->table} (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                api_brand_id    INTEGER NOT NULL UNIQUE,
                name            TEXT NOT NULL,
                slug            TEXT NOT NULL,
                status          TEXT NOT NULL,
                product_types   TEXT DEFAULT NULL,
                licenses        TEXT DEFAULT NULL,
                top_geos        TEXT DEFAULT NULL,
                offers_count    INTEGER DEFAULT 0,
                trackers_count  INTEGER DEFAULT 0,
                data            TEXT NOT NULL,
                last_synced     TEXT NOT NULL
            )
        ");
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Mirrors the brand processing logic in sync_brands_page().
     */
    private function simulateBrandSync(array $brand_data): bool {
        $api_brand_id = $brand_data['id'] ?? null;
        if (!$api_brand_id) {
            return false;
        }

        $brand_name   = $brand_data['name']   ?? 'Unknown';
        $brand_slug   = $brand_data['slug']   ?? strtolower(str_replace(' ', '-', $brand_name));
        $brand_status = $brand_data['brandStatus'] ?? 'Active';

        // Extract computed fields
        $product_types = isset($brand_data['productTypes']) && is_array($brand_data['productTypes'])
            ? implode(', ', $brand_data['productTypes']) : '';
        $licenses = isset($brand_data['licenses']) && is_array($brand_data['licenses'])
            ? implode(', ', $brand_data['licenses']) : '';

        $top_geos_arr = [];
        if (isset($brand_data['topGeos']['countries']) && is_array($brand_data['topGeos']['countries'])) {
            $top_geos_arr = array_merge($top_geos_arr, $brand_data['topGeos']['countries']);
        }
        if (isset($brand_data['topGeos']['markets']) && is_array($brand_data['topGeos']['markets'])) {
            $top_geos_arr = array_merge($top_geos_arr, $brand_data['topGeos']['markets']);
        }
        $top_geos = implode(', ', $top_geos_arr);

        // Warn if brand has offers but no topGeos
        $offers_count = isset($brand_data['offersCount']) ? intval($brand_data['offersCount'])
            : (isset($brand_data['offers']) && is_array($brand_data['offers']) ? count($brand_data['offers']) : 0);

        if (empty($top_geos_arr) && $offers_count > 0) {
            $this->error_log[] = sprintf(
                '[DataFlair Sync] Brand #%d (%s): has %d offer(s) but no topGeos',
                $api_brand_id, $brand_name, $offers_count
            );
        }

        $trackers_count = 0;
        if (isset($brand_data['offers']) && is_array($brand_data['offers'])) {
            foreach ($brand_data['offers'] as $offer) {
                if (isset($offer['trackers']) && is_array($offer['trackers'])) {
                    $trackers_count += count($offer['trackers']);
                }
            }
        }

        $now      = date('Y-m-d H:i:s');
        $data_col = json_encode($brand_data);

        $check = $this->pdo->prepare("SELECT id FROM {$this->table} WHERE api_brand_id = ?");
        $check->execute([$api_brand_id]);
        $existing = $check->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $stmt = $this->pdo->prepare(
                "UPDATE {$this->table}
                 SET name=?, slug=?, status=?, product_types=?, licenses=?,
                     top_geos=?, offers_count=?, trackers_count=?, data=?, last_synced=?
                 WHERE api_brand_id=?"
            );
            $stmt->execute([
                $brand_name, $brand_slug, $brand_status, $product_types, $licenses,
                $top_geos, $offers_count, $trackers_count, $data_col, $now,
                $api_brand_id,
            ]);
        } else {
            $stmt = $this->pdo->prepare(
                "INSERT INTO {$this->table}
                     (api_brand_id, name, slug, status, product_types, licenses,
                      top_geos, offers_count, trackers_count, data, last_synced)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)"
            );
            $stmt->execute([
                $api_brand_id, $brand_name, $brand_slug, $brand_status, $product_types, $licenses,
                $top_geos, $offers_count, $trackers_count, $data_col, $now,
            ]);
        }

        return true;
    }

    private function fetchBrand(int $api_brand_id): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE api_brand_id = ?");
        $stmt->execute([$api_brand_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function rowCount(): int {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$this->table}")->fetchColumn();
    }

    // ── Tests 11-15 ──────────────────────────────────────────────────────────

    /** Test 11: BRAND TOP GEOS EXTRACTED — CSV column correct */
    public function test_brand_top_geos_extracted_to_csv_column(): void {
        $brand = [
            'id' => 100, 'name' => 'Test Brand', 'slug' => 'test-brand',
            'brandStatus' => 'Active',
            'productTypes' => ['Casino'],
            'licenses' => ['MGA'],
            'topGeos' => [
                'countries' => ['GB', 'DE'],
                'markets'   => ['EMEA'],
            ],
            'offersCount' => 2,
            'offers' => [],
        ];

        $this->simulateBrandSync($brand);

        $row = $this->fetchBrand(100);
        $this->assertNotNull($row);
        $this->assertSame('GB, DE, EMEA', $row['top_geos']);
    }

    /** Test 12: BRAND WITH OFFERS BUT NO GEOS — warning logged */
    public function test_brand_with_offers_but_no_geos_logs_warning(): void {
        $brand = [
            'id' => 101, 'name' => 'No Geo Brand', 'slug' => 'no-geo-brand',
            'brandStatus' => 'Active',
            'productTypes' => ['Casino'],
            'licenses' => [],
            'topGeos' => ['countries' => [], 'markets' => []],
            'offersCount' => 5,
            'offers' => [],
        ];

        $this->simulateBrandSync($brand);

        $this->assertNotEmpty($this->error_log, 'Should have logged a warning');
        $this->assertTrue(
            (bool) strpos($this->error_log[0], 'no topGeos'),
            'Warning should mention "no topGeos". Got: ' . $this->error_log[0]
        );
    }

    /** Test 13: BRAND OFFERS COUNT STORED */
    public function test_brand_offers_count_is_stored(): void {
        $brand = [
            'id' => 102, 'name' => 'Count Brand', 'slug' => 'count-brand',
            'brandStatus' => 'Active', 'productTypes' => [], 'licenses' => [],
            'topGeos' => ['countries' => ['US'], 'markets' => []],
            'offersCount' => 4,
            'offers' => [
                ['trackers' => [['id' => 1], ['id' => 2]]],
                ['trackers' => [['id' => 3]]],
                ['trackers' => []],
                ['trackers' => [['id' => 4], ['id' => 5]]],
            ],
        ];

        $this->simulateBrandSync($brand);

        $row = $this->fetchBrand(102);
        $this->assertSame(4, (int) $row['offers_count']);
        $this->assertSame(5, (int) $row['trackers_count']);
    }

    /** Test 14: BRAND UPSERT — second sync updates, no duplicate rows */
    public function test_brand_upsert_no_duplicates(): void {
        $brand = [
            'id' => 103, 'name' => 'Original Name', 'slug' => 'original-slug',
            'brandStatus' => 'Active', 'productTypes' => [], 'licenses' => [],
            'topGeos' => ['countries' => ['US'], 'markets' => []],
            'offersCount' => 1, 'offers' => [],
        ];

        $this->simulateBrandSync($brand);
        $this->assertSame(1, $this->rowCount());

        $brand['name']        = 'Updated Name';
        $brand['offersCount'] = 3;
        $this->simulateBrandSync($brand);

        $this->assertSame(1, $this->rowCount(), 'Should still be 1 row after upsert');
        $row = $this->fetchBrand(103);
        $this->assertSame('Updated Name', $row['name']);
        $this->assertSame(3, (int) $row['offers_count']);
    }

    /** Test 15: BRAND WITH NO OFFERS AND NO GEOS — no false warning */
    public function test_brand_with_no_offers_and_no_geos_does_not_warn(): void {
        $brand = [
            'id' => 104, 'name' => 'Empty Brand', 'slug' => 'empty-brand',
            'brandStatus' => 'Active', 'productTypes' => [], 'licenses' => [],
            'topGeos' => ['countries' => [], 'markets' => []],
            'offersCount' => 0,
            'offers' => [],
        ];

        $this->simulateBrandSync($brand);

        $this->assertEmpty($this->error_log,
            'Should not warn when brand has 0 offers (no offers = no geos is expected)');
    }
}
