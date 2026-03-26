<?php
/**
 * Integration tests for fetch_and_store_toplist() sync logic.
 *
 * Tests use an in-memory SQLite database to verify DB writes without a live MySQL server.
 * The simulateSync() helper mirrors what fetch_and_store_toplist() does after the API call:
 *   1. Run DataIntegrityChecker::validate()
 *   2. Upsert the row by api_toplist_id
 *
 * Tests 1-10 from the Phase 4 spec are covered here.
 */

use PHPUnit\Framework\TestCase;

class SyncToplistTest extends TestCase {

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
     * Mirrors the DB upsert logic in fetch_and_store_toplist().
     * Calls DataIntegrityChecker then writes to SQLite.
     */
    private function simulateSync(array $toplist_data, string $raw_body): bool {
        $integrity = DataFlair_DataIntegrityChecker::validate($toplist_data);

        $api_id  = $toplist_data['id'];
        $now     = date('Y-m-d H:i:s');
        $pub_at  = isset($toplist_data['publishedAt'])
            ? date('Y-m-d H:i:s', strtotime($toplist_data['publishedAt']))
            : null;
        $warnings_json = !empty($integrity['warnings']) ? json_encode($integrity['warnings']) : null;

        $check = $this->pdo->prepare("SELECT id FROM {$this->table} WHERE api_toplist_id = ?");
        $check->execute([$api_id]);
        $existing = $check->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $stmt = $this->pdo->prepare(
                "UPDATE {$this->table}
                 SET name=?, slug=?, current_period=?, published_at=?,
                     item_count=?, locked_count=?, sync_warnings=?,
                     data=?, version=?, last_synced=?
                 WHERE api_toplist_id=?"
            );
            $stmt->execute([
                $toplist_data['name'] ?? '',
                $toplist_data['slug'] ?? null,
                $toplist_data['currentPeriod'] ?? null,
                $pub_at,
                $integrity['item_count'],
                $integrity['locked_count'],
                $warnings_json,
                $raw_body,
                $toplist_data['version'] ?? '',
                $now,
                $api_id,
            ]);
        } else {
            $stmt = $this->pdo->prepare(
                "INSERT INTO {$this->table}
                     (api_toplist_id, name, slug, current_period, published_at,
                      item_count, locked_count, sync_warnings, data, version, last_synced)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)"
            );
            $stmt->execute([
                $api_id,
                $toplist_data['name'] ?? '',
                $toplist_data['slug'] ?? null,
                $toplist_data['currentPeriod'] ?? null,
                $pub_at,
                $integrity['item_count'],
                $integrity['locked_count'],
                $warnings_json,
                $raw_body,
                $toplist_data['version'] ?? '',
                $now,
            ]);
        }

        return true;
    }

    private function fetchRow(int $api_id): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE api_toplist_id = ?");
        $stmt->execute([$api_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function rowCount(): int {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$this->table}")->fetchColumn();
    }

    // ── Tests 1-10 ───────────────────────────────────────────────────────────

    /** Test 1: COMPLETE SYNC — all fields saved correctly */
    public function test_complete_sync_saves_all_fields(): void {
        $raw  = $this->loadFixture('api-toplist-complete.json');
        $data = json_decode($raw, true)['data'];

        $this->simulateSync($data, $raw);

        $row = $this->fetchRow(42);
        $this->assertNotNull($row);
        $this->assertSame(42, (int) $row['api_toplist_id']);
        $this->assertSame('Best Brazil Casinos', $row['name']);
        $this->assertSame('brazil-casinos', $row['slug']);
        $this->assertSame('2026-03', $row['current_period']);
        $this->assertNotEmpty($row['published_at']);
        $this->assertSame(5, (int) $row['item_count']);
        $this->assertSame(2, (int) $row['locked_count']);
        $this->assertNull($row['sync_warnings']);
        $this->assertNotEmpty($row['last_synced']);

        $stored = json_decode($row['data'], true);
        $this->assertSame(42, $stored['data']['id']);
        $this->assertSame('Best Brazil Casinos', $stored['data']['name']);
    }

    /**
     * Test 2: OFFER GEOS SAVED — the bug fix verification.
     * This is THE most important test — proves geos are in the stored JSON.
     */
    public function test_offer_geos_are_stored_in_json_blob(): void {
        $raw  = $this->loadFixture('api-toplist-complete.json');
        $data = json_decode($raw, true)['data'];

        $this->simulateSync($data, $raw);

        $row    = $this->fetchRow(42);
        $stored = json_decode($row['data'], true)['data'];

        foreach ($stored['items'] as $item) {
            $pos  = $item['position'];
            $geos = $item['offer']['geos'] ?? null;

            $this->assertNotNull($geos, "Position {$pos}: offer.geos should not be null in stored JSON");
            $this->assertArrayHasKey('countries', $geos, "Position {$pos}: offer.geos should have 'countries'");
            $this->assertArrayHasKey('markets', $geos, "Position {$pos}: offer.geos should have 'markets'");
            $this->assertIsArray($geos['countries'], "Position {$pos}: offer.geos.countries should be array");
            $this->assertIsArray($geos['markets'], "Position {$pos}: offer.geos.markets should be array");
        }
    }

    /** Test 3: OFFER GEOS MISSING → warning generated, sync still saves data */
    public function test_missing_offer_geos_generates_warning_but_data_still_saved(): void {
        $raw  = $this->loadFixture('api-toplist-missing-geos.json');
        $data = json_decode($raw, true)['data'];

        $result = $this->simulateSync($data, $raw);
        $this->assertTrue($result, 'Sync should succeed even with warnings');

        $row = $this->fetchRow(43);
        $this->assertNotNull($row, 'Row should be saved despite warnings');

        $warnings = json_decode($row['sync_warnings'], true);
        $this->assertIsArray($warnings);

        $found = false;
        foreach ($warnings as $w) {
            if (str_contains($w, 'Position 3') && str_contains($w, 'geos')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found,
            'Expected position 3 geos warning. Got: ' . implode('; ', $warnings));
    }

    /** Test 4: TRACKER LINKS SAVED — trackerLink and tcLink in stored JSON */
    public function test_tracker_links_are_stored_in_json(): void {
        $raw  = $this->loadFixture('api-toplist-complete.json');
        $data = json_decode($raw, true)['data'];

        $this->simulateSync($data, $raw);

        $row    = $this->fetchRow(42);
        $stored = json_decode($row['data'], true)['data'];

        foreach ($stored['items'] as $item) {
            $pos = $item['position'];
            foreach ($item['offer']['trackers'] as $ti => $tracker) {
                $this->assertNotEmpty($tracker['trackerLink'],
                    "Position {$pos}, tracker #{$ti}: trackerLink should be stored");
                $this->assertNotEmpty($tracker['tcLink'],
                    "Position {$pos}, tracker #{$ti}: tcLink should be stored");
            }
        }
    }

    /** Test 5: TRACKER LINKS MISSING — warning generated */
    public function test_missing_tracker_link_generates_warning(): void {
        $raw  = $this->loadFixture('api-toplist-complete.json');
        $data = json_decode($raw, true);

        // Remove trackerLink from item at position 2 (index 1), tracker 0
        $data['data']['items'][1]['offer']['trackers'][0]['trackerLink'] = '';
        $raw_modified = json_encode($data);

        $this->simulateSync($data['data'], $raw_modified);

        $row      = $this->fetchRow(42);
        $warnings = json_decode($row['sync_warnings'], true) ?? [];

        $found = false;
        foreach ($warnings as $w) {
            if (str_contains($w, 'Position 2') && str_contains($w, 'missing trackerLink')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found,
            'Expected Position 2 trackerLink warning. Got: ' . implode('; ', $warnings));
    }

    /** Test 6: UPSERT BY api_toplist_id — update replaces data, no duplicate rows */
    public function test_upsert_updates_existing_row_without_duplicating(): void {
        $raw  = $this->loadFixture('api-toplist-complete.json');
        $data = json_decode($raw, true);
        $this->simulateSync($data['data'], $raw);
        $this->assertSame(1, $this->rowCount());

        // Second sync — different name, same api_toplist_id
        $data['data']['name'] = 'Updated Brazil Casinos';
        $raw2 = json_encode($data);
        $this->simulateSync($data['data'], $raw2);

        $this->assertSame(1, $this->rowCount(), 'Should still be 1 row after upsert');
        $row = $this->fetchRow(42);
        $this->assertSame('Updated Brazil Casinos', $row['name']);
    }

    /** Test 7: MONTHLY ROTATION — same ID, new period, data updated */
    public function test_monthly_rotation_updates_period_and_item_count(): void {
        $raw  = $this->loadFixture('api-toplist-complete.json');
        $data = json_decode($raw, true);
        $this->simulateSync($data['data'], $raw);

        $row = $this->fetchRow(42);
        $this->assertSame('2026-03', $row['current_period']);

        // Simulate April rotation: new period, new items (only 3)
        $data['data']['currentPeriod'] = '2026-04';
        $data['data']['publishedAt']   = '2026-04-01T12:00:00+00:00';
        $data['data']['items']         = array_slice($data['data']['items'], 0, 3);
        $raw2 = json_encode($data);

        $this->simulateSync($data['data'], $raw2);

        $row = $this->fetchRow(42);
        $this->assertSame('2026-04', $row['current_period']);
        $this->assertSame(3, (int) $row['item_count']);

        $stored = json_decode($row['data'], true)['data'];
        $this->assertCount(3, $stored['items']);
    }

    /** Test 8: SLUG STORED — can look up by slug */
    public function test_slug_is_stored_and_retrievable_by_slug(): void {
        $raw  = $this->loadFixture('api-toplist-complete.json');
        $data = json_decode($raw, true)['data'];

        $this->simulateSync($data, $raw);

        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE slug = ?");
        $stmt->execute(['brazil-casinos']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotNull($row, 'Should find row by slug');
        $this->assertSame('brazil-casinos', $row['slug']);
        $this->assertSame(42, (int) $row['api_toplist_id']);
    }

    /** Test 9: EMPTY ITEMS — valid but empty, sync succeeds */
    public function test_empty_items_syncs_without_errors(): void {
        $raw  = $this->loadFixture('api-toplist-complete.json');
        $data = json_decode($raw, true);
        $data['data']['items'] = [];
        $raw_modified = json_encode($data);

        $result = $this->simulateSync($data['data'], $raw_modified);
        $this->assertTrue($result);

        $row = $this->fetchRow(42);
        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row['item_count']);
        $this->assertSame(0, (int) $row['locked_count']);
    }

    /** Test 10: LOCKED ITEMS — count correct, dealId preserved in JSON */
    public function test_locked_items_are_counted_and_deal_ids_preserved(): void {
        $raw  = $this->loadFixture('api-toplist-locked-items.json');
        $data = json_decode($raw, true)['data'];

        $this->simulateSync($data, $raw);

        $row = $this->fetchRow(44);
        $this->assertSame(10, (int) $row['item_count']);
        $this->assertSame(3, (int) $row['locked_count']); // positions 1, 3, 7

        $stored         = json_decode($row['data'], true)['data'];
        $locked_positions = [];
        foreach ($stored['items'] as $item) {
            if ($item['isLocked']) {
                $locked_positions[] = $item['position'];
                $this->assertNotEmpty($item['dealId'],
                    "Position {$item['position']}: dealId should be preserved");
            }
        }
        sort($locked_positions);
        $this->assertSame([1, 3, 7], $locked_positions);
    }

    /** Test 11: ExternalId from toplist endpoint is preserved in stored JSON blob */
    public function test_toplist_external_id_is_preserved_in_data_blob(): void {
        $raw  = $this->loadFixture('api-toplist-complete.json');
        $data = json_decode($raw, true);

        $data['data']['ExternalId'] = 'toplist-ext-42';
        $raw_modified = json_encode($data);

        $this->simulateSync($data['data'], $raw_modified);

        $row = $this->fetchRow(42);
        $this->assertNotNull($row);

        $stored = json_decode($row['data'], true);
        $this->assertSame('toplist-ext-42', $stored['data']['ExternalId'] ?? null);
    }
}
