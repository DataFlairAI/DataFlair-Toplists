<?php
/**
 * Shortcode render simulation tests (tests 22-29).
 *
 * These tests do NOT call the actual WordPress shortcode handler — that
 * requires a running WP install. Instead, simulateShortcodeRender() mirrors
 * what toplist_shortcode() does: query SQLite by id or slug, decode the JSON,
 * and produce a basic HTML string suitable for assertion.
 *
 * No Brain\Monkey, no WordPress bootstrap required. Pure PDO + PHPUnit.
 */

use PHPUnit\Framework\TestCase;

class ShortcodeTest extends TestCase {

    private PDO    $pdo;
    private string $table = 'wp_dataflair_toplists';

    protected function setUp(): void {
        parent::setUp();
        $this->setupInMemoryDb();
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function setupInMemoryDb(): void {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("
            CREATE TABLE {$this->table} (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                api_toplist_id INTEGER NOT NULL UNIQUE,
                name           TEXT NOT NULL,
                slug           TEXT DEFAULT NULL,
                current_period TEXT DEFAULT NULL,
                published_at   TEXT DEFAULT NULL,
                item_count     INTEGER DEFAULT 0,
                locked_count   INTEGER DEFAULT 0,
                sync_warnings  TEXT DEFAULT NULL,
                data           TEXT NOT NULL,
                version        TEXT DEFAULT NULL,
                last_synced    TEXT NOT NULL
            )
        ");
    }

    private function loadFixture(string $filename): string {
        return file_get_contents(__DIR__ . '/../fixtures/' . $filename);
    }

    /**
     * Insert a toplist row directly into SQLite.
     *
     * @param int    $api_id    The api_toplist_id value
     * @param string $name      Display name
     * @param string $slug      URL slug
     * @param string $rawJson   Full JSON body (the entire API envelope)
     * @param int    $itemCount How many items to record
     */
    private function insertToplist(
        int    $api_id,
        string $name,
        string $slug,
        string $rawJson,
        int    $itemCount = 0
    ): void {
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->table}
                 (api_toplist_id, name, slug, data, item_count, version, last_synced)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $api_id,
            $name,
            $slug,
            $rawJson,
            $itemCount,
            '1.0',
            date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Simulates what toplist_shortcode() does — without calling WordPress.
     *
     * Steps:
     *   1. Resolve the toplist row from SQLite (by id or slug)
     *   2. If not found, return an error string containing "not found"
     *   3. If found, decode stored JSON and produce a basic HTML snippet
     *      containing brand names and offer text, limited by $limit
     *
     * @param array $atts Shortcode attributes: id, slug, limit, title
     * @return string HTML output or error string
     */
    private function simulateShortcodeRender(array $atts): string {
        $id    = isset($atts['id'])    ? (int) $atts['id']    : 0;
        $slug  = isset($atts['slug'])  ? trim($atts['slug'])  : '';
        $limit = isset($atts['limit']) ? (int) $atts['limit'] : 0;
        $title = isset($atts['title']) ? trim($atts['title']) : '';

        // 1. Resolve toplist row
        $row = null;
        if ($id > 0) {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM {$this->table} WHERE api_toplist_id = ? LIMIT 1"
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } elseif ($slug !== '') {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM {$this->table} WHERE slug = ? LIMIT 1"
            );
            $stmt->execute([$slug]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        // 2. Not found — return error string (no PHP error, just a message)
        if ($row === null) {
            return '<p class="dataflair-error">Toplist not found</p>';
        }

        // 3. Decode stored JSON
        $decoded = json_decode($row['data'], true);
        $toplist = $decoded['data'] ?? [];
        $items   = $toplist['items'] ?? [];

        // Apply limit
        if ($limit > 0) {
            $items = array_slice($items, 0, $limit);
        }

        // Resolve display title: shortcode attribute > stored name
        $display_title = $title !== '' ? $title : ($toplist['name'] ?? '');

        // Build basic HTML — enough for assertions, not for production rendering
        $html  = '<div class="dataflair-toplist">';
        $html .= '<h2 class="dataflair-title">' . htmlspecialchars($display_title) . '</h2>';
        $html .= '<ol class="dataflair-items">';

        foreach ($items as $item) {
            $brand_name   = $item['brand']['name']       ?? '';
            $offer_text   = $item['offer']['offerText']  ?? '';
            $geos         = $item['offer']['geos']        ?? [];
            $trackers     = $item['offer']['trackers']    ?? [];
            $tracker_link = '';
            if (!empty($trackers[0]['trackerLink'])) {
                $tracker_link = $trackers[0]['trackerLink'];
            }

            $html .= '<li class="dataflair-item">';
            $html .= '<span class="dataflair-brand">' . htmlspecialchars($brand_name) . '</span>';
            $html .= '<span class="dataflair-offer">' . htmlspecialchars($offer_text) . '</span>';

            // Embed geos as data attribute so tests can assert accessibility
            if (!empty($geos['countries'])) {
                $html .= '<span class="dataflair-geos" data-countries="'
                    . htmlspecialchars(implode(',', $geos['countries'])) . '"></span>';
            }

            // Tracker link as CTA href
            if ($tracker_link !== '') {
                $html .= '<a class="dataflair-cta" href="'
                    . htmlspecialchars($tracker_link) . '">Get Offer</a>';
            }

            $html .= '</li>';
        }

        $html .= '</ol>';
        $html .= '</div>';

        return $html;
    }

    // ── Tests 22-29 ──────────────────────────────────────────────────────────

    /**
     * Test 22: SHORTCODE BY ID — renders correctly.
     * Inserts toplist with api_toplist_id=42 and full fixture JSON.
     * Asserts output contains brand names and offer text.
     */
    public function test_shortcode_by_id_renders_correctly(): void {
        $raw  = $this->loadFixture('api-toplist-complete.json');
        $data = json_decode($raw, true)['data'];

        $this->insertToplist(42, $data['name'], $data['slug'], $raw, count($data['items']));

        $output = $this->simulateShortcodeRender(['id' => 42]);

        $this->assertNotEmpty($output, 'Render output should not be empty');
        $this->assertFalse(
            strpos($output, 'not found') !== false,
            'Output should not contain "not found" for a valid toplist'
        );

        // Assert brand names are present
        $this->assertNotFalse(strpos($output, 'Casino Alpha'), 'Brand "Casino Alpha" should appear in output');
        $this->assertNotFalse(strpos($output, 'Casino Beta'),  'Brand "Casino Beta" should appear in output');
        $this->assertNotFalse(strpos($output, 'Casino Gamma'), 'Brand "Casino Gamma" should appear in output');

        // Assert offer text is present
        $this->assertNotFalse(
            strpos($output, '100% up to R$500 + 50 Free Spins'),
            'Offer text for Casino Alpha should appear in output'
        );
        $this->assertNotFalse(
            strpos($output, '200% up to R$1000'),
            'Offer text for Casino Beta should appear in output'
        );
    }

    /**
     * Test 23: SHORTCODE BY SLUG — renders correctly.
     * Looks up by slug="brazil-casinos". Asserts brand names in output.
     */
    public function test_shortcode_by_slug_renders_correctly(): void {
        $raw  = $this->loadFixture('api-toplist-complete.json');
        $data = json_decode($raw, true)['data'];

        $this->insertToplist(42, $data['name'], 'brazil-casinos', $raw, count($data['items']));

        $output = $this->simulateShortcodeRender(['slug' => 'brazil-casinos']);

        $this->assertFalse(
            strpos($output, 'not found') !== false,
            'Output should not contain "not found" for a valid slug'
        );
        $this->assertNotFalse(strpos($output, 'Casino Alpha'),   'Brand "Casino Alpha" should appear');
        $this->assertNotFalse(strpos($output, 'Casino Beta'),    'Brand "Casino Beta" should appear');
        $this->assertNotFalse(strpos($output, 'Casino Epsilon'), 'Brand "Casino Epsilon" should appear');
    }

    /**
     * Test 24: SHORTCODE BY ID — not found.
     * Look up id=999 from empty DB. Assert output contains "not found".
     */
    public function test_shortcode_by_id_not_found_returns_error(): void {
        // DB is empty — no rows inserted
        $output = $this->simulateShortcodeRender(['id' => 999]);

        $this->assertNotFalse(
            strpos($output, 'not found'),
            'Output should contain "not found" when toplist id does not exist'
        );
    }

    /**
     * Test 25: SHORTCODE BY SLUG — not found.
     * Look up slug="nonexistent". Assert output contains "not found".
     */
    public function test_shortcode_by_slug_not_found_returns_error(): void {
        // DB is empty — no rows inserted
        $output = $this->simulateShortcodeRender(['slug' => 'nonexistent']);

        $this->assertNotFalse(
            strpos($output, 'not found'),
            'Output should contain "not found" when slug does not exist'
        );
    }

    /**
     * Test 26: SHORTCODE — offer geos accessible in render.
     * Insert toplist where item offers have geos.countries=["GB","DE"].
     * Simulate render, assert the geos are accessible from stored JSON.
     */
    public function test_shortcode_render_offer_geos_accessible(): void {
        $raw  = $this->loadFixture('api-toplist-complete.json');
        $data = json_decode($raw, true);

        // Override item geos to GB, DE so test is explicit and independent of fixture values
        $data['data']['items'][0]['offer']['geos'] = [
            'countries' => ['GB', 'DE'],
            'markets'   => ['EU'],
        ];
        $modifiedRaw = json_encode($data);

        $this->insertToplist(42, $data['data']['name'], $data['data']['slug'], $modifiedRaw, 5);

        $output = $this->simulateShortcodeRender(['id' => 42]);

        // Verify the stored JSON still carries the geos (render reads from stored data)
        $stored = json_decode($modifiedRaw, true)['data'];
        $item0  = $stored['items'][0];
        $geos   = $item0['offer']['geos'] ?? null;

        $this->assertNotNull($geos, 'offer.geos should not be null in stored JSON');
        $this->assertArrayHasKey('countries', $geos, 'offer.geos should have countries key');
        $this->assertContains('GB', $geos['countries'], 'GB should be in offer geos countries');
        $this->assertContains('DE', $geos['countries'], 'DE should be in offer geos countries');

        // The render output should surface geos (data attribute or inline)
        $this->assertNotFalse(
            strpos($output, 'dataflair-geos'),
            'Render output should contain geo data for the first item'
        );
    }

    /**
     * Test 27: SHORTCODE — tracker links rendered.
     * Insert toplist where items have trackers with trackerLink.
     * Assert trackerLink is accessible from stored JSON (used as CTA href).
     */
    public function test_shortcode_render_tracker_links_accessible(): void {
        $raw  = $this->loadFixture('api-toplist-complete.json');
        $data = json_decode($raw, true)['data'];

        $this->insertToplist(42, $data['name'], $data['slug'], $raw, count($data['items']));

        $output = $this->simulateShortcodeRender(['id' => 42]);

        // The fixture's first item tracker link
        $expectedLink = 'https://track.example.com/go/casino-alpha-br';
        $this->assertNotFalse(
            strpos($output, $expectedLink),
            'Tracker link for Casino Alpha should appear as CTA href in render output'
        );

        // Confirm CTA anchor elements are rendered
        $this->assertNotFalse(
            strpos($output, 'dataflair-cta'),
            'CTA anchor elements should be rendered'
        );
    }

    /**
     * Test 28: SHORTCODE — limit attribute works.
     * Insert toplist with 5 items. Apply limit=3. Assert only 3 items returned.
     */
    public function test_shortcode_limit_attribute_restricts_items(): void {
        $raw  = $this->loadFixture('api-toplist-complete.json');
        $data = json_decode($raw, true)['data'];

        // Fixture has 5 items: Casino Alpha, Beta, Gamma, Delta, Epsilon
        $this->assertCount(5, $data['items'], 'Fixture should have 5 items for this test');
        $this->insertToplist(42, $data['name'], $data['slug'], $raw, 5);

        $output = $this->simulateShortcodeRender(['id' => 42, 'limit' => 3]);

        // First 3 brands should appear
        $this->assertNotFalse(strpos($output, 'Casino Alpha'), 'Casino Alpha (pos 1) should appear');
        $this->assertNotFalse(strpos($output, 'Casino Beta'),  'Casino Beta (pos 2) should appear');
        $this->assertNotFalse(strpos($output, 'Casino Gamma'), 'Casino Gamma (pos 3) should appear');

        // Last 2 brands should NOT appear
        $this->assertFalse(
            strpos($output, 'Casino Delta') !== false,
            'Casino Delta (pos 4) should NOT appear with limit=3'
        );
        $this->assertFalse(
            strpos($output, 'Casino Epsilon') !== false,
            'Casino Epsilon (pos 5) should NOT appear with limit=3'
        );
    }

    /**
     * Test 29: SHORTCODE — title attribute.
     * Test title override returns the custom title instead of the stored name.
     */
    public function test_shortcode_title_attribute_overrides_stored_name(): void {
        $raw  = $this->loadFixture('api-toplist-complete.json');
        $data = json_decode($raw, true)['data'];

        $this->insertToplist(42, $data['name'], $data['slug'], $raw, count($data['items']));

        $customTitle = 'Top Casinos for VIP Players';
        $output = $this->simulateShortcodeRender(['id' => 42, 'title' => $customTitle]);

        // Custom title should appear
        $this->assertNotFalse(
            strpos($output, $customTitle),
            'Custom title attribute should appear in render output'
        );

        // Default stored name should NOT appear (it was overridden)
        $this->assertFalse(
            strpos($output, 'Best Brazil Casinos') !== false,
            'Stored toplist name should be replaced by custom title attribute'
        );
    }
}
