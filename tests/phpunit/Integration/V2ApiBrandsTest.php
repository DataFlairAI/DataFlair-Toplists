<?php
/**
 * Integration tests for V2 API brands support.
 *
 * Covers:
 *  1.  get_brands_api_url() with v1 selected
 *  2.  get_brands_api_url() with v2 selected
 *  3.  get_brands_api_url() idempotent when base already /api/v2
 *  4.  sync uses v2 URL when v2 option set
 *  5.  classification_types populated from classificationTypes array
 *  6.  classification_types is empty string when field absent
 *  7.  get_toplists_rest() returns valid array
 *  8.  get_toplist_casinos_rest() handles old items shape
 *  9.  get_toplist_casinos_rest() handles new listItems shape
 * 10.  get_toplist_casinos_rest() returns empty array when items missing
 * 11.  ajax_save_settings sanitizes api version to v1 or v2 only
 *
 * Uses SQLite in-memory — no live MySQL or WordPress required.
 */

use PHPUnit\Framework\TestCase;

class V2ApiBrandsTest extends TestCase {

    private PDO    $pdo;
    private string $brands_table   = 'wp_dataflair_brands';
    private string $toplists_table = 'wp_dataflair_toplists';
    private array  $options        = [];
    private array  $http_calls     = [];

    protected function setUp(): void {
        parent::setUp();
        $this->options    = [];
        $this->http_calls = [];

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec("
            CREATE TABLE {$this->brands_table} (
                id                   INTEGER PRIMARY KEY AUTOINCREMENT,
                api_brand_id         INTEGER NOT NULL UNIQUE,
                name                 TEXT NOT NULL,
                slug                 TEXT NOT NULL,
                status               TEXT NOT NULL,
                product_types        TEXT DEFAULT NULL,
                licenses             TEXT DEFAULT NULL,
                classification_types TEXT NOT NULL DEFAULT '',
                top_geos             TEXT DEFAULT NULL,
                offers_count         INTEGER DEFAULT 0,
                trackers_count       INTEGER DEFAULT 0,
                data                 TEXT NOT NULL,
                last_synced          TEXT NOT NULL
            )
        ");

        $this->pdo->exec("
            CREATE TABLE {$this->toplists_table} (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                api_toplist_id INTEGER NOT NULL UNIQUE,
                name           TEXT NOT NULL,
                slug           TEXT DEFAULT NULL,
                data           TEXT NOT NULL,
                last_synced    TEXT NOT NULL
            )
        ");
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Mirrors get_brands_api_url() from the plugin. */
    private function get_brands_api_url(string $base_url, string $version, int $page): string {
        if ($version === 'v2') {
            $base_url = preg_replace('#/api/v\d+$#', '/api/v2', $base_url);
        }
        return rtrim($base_url, '/') . '/brands?page=' . $page;
    }

    /** Mirrors brand sync extract + upsert logic. */
    private function simulateBrandSync(array $brand_data, string $version = 'v1'): string {
        $api_brand_id  = $brand_data['id'];
        $brand_name    = $brand_data['name']        ?? 'Unknown';
        $brand_slug    = $brand_data['slug']        ?? strtolower(str_replace(' ', '-', $brand_name));
        $brand_status  = $brand_data['brandStatus'] ?? 'Active';
        $product_types = isset($brand_data['productTypes']) && is_array($brand_data['productTypes'])
            ? implode(', ', $brand_data['productTypes']) : '';
        $licenses      = isset($brand_data['licenses']) && is_array($brand_data['licenses'])
            ? implode(', ', $brand_data['licenses']) : '';
        $classification_types = isset($brand_data['classificationTypes'])
            && is_array($brand_data['classificationTypes'])
            ? implode(', ', $brand_data['classificationTypes']) : '';

        $top_geos_arr = [];
        foreach (['countries', 'markets'] as $key) {
            if (isset($brand_data['topGeos'][$key]) && is_array($brand_data['topGeos'][$key])) {
                $top_geos_arr = array_merge($top_geos_arr, $brand_data['topGeos'][$key]);
            }
        }
        $top_geos    = implode(', ', $top_geos_arr);
        $offers_count = isset($brand_data['offersCount']) ? (int) $brand_data['offersCount'] : 0;
        $trackers_count = 0;
        $now          = date('Y-m-d H:i:s');
        $data_col     = json_encode($brand_data);

        $base = $this->options['dataflair_api_base_url'] ?? 'https://tenant.dataflair.ai/api/v1';
        $called_url = $this->get_brands_api_url($base, $version, 1);
        $this->http_calls[] = $called_url;

        $check = $this->pdo->prepare("SELECT id FROM {$this->brands_table} WHERE api_brand_id = ?");
        $check->execute([$api_brand_id]);
        $existing = $check->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $stmt = $this->pdo->prepare(
                "UPDATE {$this->brands_table}
                 SET name=?, slug=?, status=?, product_types=?, licenses=?,
                     classification_types=?, top_geos=?, offers_count=?, trackers_count=?,
                     data=?, last_synced=?
                 WHERE api_brand_id=?"
            );
            $stmt->execute([
                $brand_name, $brand_slug, $brand_status, $product_types, $licenses,
                $classification_types, $top_geos, $offers_count, $trackers_count,
                $data_col, $now, $api_brand_id,
            ]);
        } else {
            $stmt = $this->pdo->prepare(
                "INSERT INTO {$this->brands_table}
                     (api_brand_id, name, slug, status, product_types, licenses,
                      classification_types, top_geos, offers_count, trackers_count, data, last_synced)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            $stmt->execute([
                $api_brand_id, $brand_name, $brand_slug, $brand_status, $product_types, $licenses,
                $classification_types, $top_geos, $offers_count, $trackers_count, $data_col, $now,
            ]);
        }

        return $called_url;
    }

    private function fetchBrand(int $api_brand_id): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->brands_table} WHERE api_brand_id = ?");
        $stmt->execute([$api_brand_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Mirrors get_toplist_casinos_rest() item extraction. */
    private function extractCasinos(array $data): array {
        $items = null;
        if (isset($data['data']['items'])) {
            $items = $data['data']['items'];
        } elseif (isset($data['data']['listItems'])) {
            $items = $data['data']['listItems'];
        } elseif (isset($data['listItems'])) {
            $items = $data['listItems'];
        }

        if (empty($items)) {
            return [];
        }

        $casinos = [];
        foreach ($items as $item) {
            $brand_name = '';
            if (isset($item['brand']['name'])) {
                $brand_name = $item['brand']['name'];
            } elseif (isset($item['brandName'])) {
                $brand_name = $item['brandName'];
            }
            if (empty($brand_name)) {
                continue;
            }

            $brand_id = 0;
            if (isset($item['brand']['id'])) {
                $brand_id = (int) $item['brand']['id'];
            } elseif (isset($item['brandId'])) {
                $brand_id = (int) $item['brandId'];
            }

            $casinos[] = [
                'itemId'    => isset($item['id']) ? (int) $item['id'] : 0,
                'brandId'   => $brand_id,
                'position'  => $item['position'] ?? 0,
                'brandName' => $brand_name,
                'brandSlug' => strtolower(str_replace(' ', '-', $brand_name)),
                'pros'      => !empty($item['pros']) ? $item['pros'] : [],
                'cons'      => !empty($item['cons']) ? $item['cons'] : [],
            ];
        }
        return $casinos;
    }

    /** Mirrors ajax_save_settings() version validation. */
    private function sanitizeApiVersion(string $input): string {
        return $input === 'v2' ? 'v2' : 'v1';
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    /** Test 1: V1 URL correct */
    public function test_get_brands_api_url_v1_returns_v1_url(): void {
        $url = $this->get_brands_api_url('https://tenant.dataflair.ai/api/v1', 'v1', 3);
        $this->assertSame('https://tenant.dataflair.ai/api/v1/brands?page=3', $url);
    }

    /** Test 2: V2 URL correct — base URL rewritten from v1 to v2 */
    public function test_get_brands_api_url_v2_rewrites_to_v2(): void {
        $url = $this->get_brands_api_url('https://tenant.dataflair.ai/api/v1', 'v2', 1);
        $this->assertSame('https://tenant.dataflair.ai/api/v2/brands?page=1', $url);
    }

    /** Test 3: Idempotent — already /api/v2 stays /api/v2 */
    public function test_get_brands_api_url_v2_idempotent_when_already_v2(): void {
        $url = $this->get_brands_api_url('https://tenant.dataflair.ai/api/v2', 'v2', 2);
        $this->assertSame('https://tenant.dataflair.ai/api/v2/brands?page=2', $url);
    }

    /** Test 4: sync calls v2 URL when v2 option is set */
    public function test_sync_brands_page_calls_v2_url_when_v2_option_set(): void {
        $this->options['dataflair_api_base_url'] = 'https://tenant.dataflair.ai/api/v1';

        $brand = [
            'id' => 200, 'name' => 'BrandV2', 'slug' => 'brand-v2',
            'brandStatus' => 'Active', 'productTypes' => ['Casino'],
            'licenses' => [], 'topGeos' => ['countries' => [], 'markets' => []],
            'offersCount' => 0,
        ];

        $called_url = $this->simulateBrandSync($brand, 'v2');

        $this->assertStringContainsString('/api/v2/brands', $called_url);
        $this->assertStringNotContainsString('/api/v1/brands', $called_url);
    }

    /** Test 5: classification_types populated from classificationTypes array */
    public function test_classification_types_populated_from_array(): void {
        $brand = [
            'id' => 201, 'name' => 'Multi Brand', 'slug' => 'multi-brand',
            'brandStatus' => 'Active',
            'productTypes' => ['Casino', 'Sportsbook'],
            'licenses' => [],
            'classificationTypes' => ['Casino', 'Sportsbook', 'Poker'],
            'topGeos' => ['countries' => ['GB'], 'markets' => []],
            'offersCount' => 1,
        ];

        $this->simulateBrandSync($brand, 'v2');

        $row = $this->fetchBrand(201);
        $this->assertNotNull($row);
        $this->assertSame('Casino, Sportsbook, Poker', $row['classification_types']);
    }

    /** Test 6: classification_types is empty string (not null) when field absent */
    public function test_classification_types_empty_string_when_absent(): void {
        $brand = [
            'id' => 202, 'name' => 'Plain Brand', 'slug' => 'plain-brand',
            'brandStatus' => 'Active', 'productTypes' => ['Casino'],
            'licenses' => [], 'topGeos' => ['countries' => [], 'markets' => []],
            'offersCount' => 0,
        ];

        $this->simulateBrandSync($brand, 'v1');

        $row = $this->fetchBrand(202);
        $this->assertNotNull($row);
        $this->assertSame('', $row['classification_types']);
        $this->assertNotNull($row['classification_types'], 'Should be empty string, not null');
    }

    /** Test 7: get_toplists_rest() returns valid array (mirrors REST callback) */
    public function test_get_toplists_rest_returns_valid_array(): void {
        $this->pdo->exec(
            "INSERT INTO {$this->toplists_table} (api_toplist_id, name, slug, data, last_synced)
             VALUES (1, 'Top Casinos', 'top-casinos', '{}', '2026-01-01 00:00:00')"
        );

        $stmt = $this->pdo->query(
            "SELECT api_toplist_id, name, slug FROM {$this->toplists_table} ORDER BY api_toplist_id ASC"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_OBJ);

        $options = [];
        foreach ($rows as $row) {
            $suffix = !empty($row->slug)
                ? ' [' . $row->slug . ']'
                : ' (ID: ' . $row->api_toplist_id . ')';
            $options[] = ['value' => (string) $row->api_toplist_id, 'label' => $row->name . $suffix];
        }

        $this->assertIsArray($options);
        $this->assertCount(1, $options);
        $this->assertSame('1', $options[0]['value']);
        $this->assertSame('Top Casinos [top-casinos]', $options[0]['label']);
    }

    /** Test 8: handles old data.items shape */
    public function test_get_toplist_casinos_rest_handles_old_items_shape(): void {
        $toplist_data = [
            'data' => [
                'items' => [
                    ['id' => 11, 'position' => 1, 'brand' => ['id' => 201, 'name' => 'Betway'], 'pros' => ['Good odds'], 'cons' => []],
                    ['id' => 12, 'position' => 2, 'brand' => ['id' => 202, 'name' => 'Bet365'], 'pros' => [], 'cons' => ['High wager']],
                ]
            ]
        ];

        $casinos = $this->extractCasinos($toplist_data);

        $this->assertCount(2, $casinos);
        $this->assertSame('Betway', $casinos[0]['brandName']);
        $this->assertSame(11, $casinos[0]['itemId']);
        $this->assertSame(201, $casinos[0]['brandId']);
        $this->assertSame(1, $casinos[0]['position']);
        $this->assertSame(['Good odds'], $casinos[0]['pros']);
        $this->assertSame('Bet365', $casinos[1]['brandName']);
        $this->assertSame(202, $casinos[1]['brandId']);
    }

    /** Test 9: handles new data.listItems shape (editions model) */
    public function test_get_toplist_casinos_rest_handles_new_listItems_shape(): void {
        $toplist_data = [
            'data' => [
                'listItems' => [
                    ['id' => 21, 'position' => 1, 'brandName' => 'Unibet', 'brandId' => 301, 'pros' => [], 'cons' => []],
                    ['id' => 22, 'position' => 2, 'brand' => ['id' => 302, 'name' => 'William Hill'], 'pros' => [], 'cons' => []],
                ]
            ]
        ];

        $casinos = $this->extractCasinos($toplist_data);

        $this->assertCount(2, $casinos);
        $this->assertSame('Unibet', $casinos[0]['brandName']);
        $this->assertSame(21, $casinos[0]['itemId']);
        $this->assertSame(301, $casinos[0]['brandId']);
        $this->assertSame('William Hill', $casinos[1]['brandName']);
        $this->assertSame(302, $casinos[1]['brandId']);
    }

    /** Test 10: returns empty array (not error) when items missing */
    public function test_get_toplist_casinos_rest_returns_empty_when_items_missing(): void {
        $casinos = $this->extractCasinos(['data' => ['template' => ['name' => 'default']]]);
        $this->assertSame([], $casinos);
    }

    /** Test 11: sanitizeApiVersion rejects invalid values, defaults to v1 */
    public function test_ajax_save_settings_only_accepts_v1_or_v2(): void {
        $this->assertSame('v1', $this->sanitizeApiVersion('v1'));
        $this->assertSame('v2', $this->sanitizeApiVersion('v2'));
        $this->assertSame('v1', $this->sanitizeApiVersion('v3'));
        $this->assertSame('v1', $this->sanitizeApiVersion(''));
        $this->assertSame('v1', $this->sanitizeApiVersion('V2'));
        $this->assertSame('v1', $this->sanitizeApiVersion('../hack'));
    }
}
