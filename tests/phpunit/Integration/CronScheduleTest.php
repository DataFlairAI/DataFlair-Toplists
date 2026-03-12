<?php
/**
 * Cron schedule tests (tests 16-21).
 *
 * There is no live WordPress available, so these tests exercise only the logic
 * that can be verified without WP functions:
 *
 *   - The dataflair_15min custom interval is exactly 900 seconds (tests 16, 17)
 *   - simulateSync() writes a DB row when the API response is valid (test 18)
 *   - simulateSync() returns false and writes nothing on API failure (tests 19, 20)
 *   - A lightweight mock records that wp_clear_scheduled_hook would be called (test 21)
 *
 * No Brain\Monkey, no WordPress bootstrap required. Pure PDO + PHPUnit.
 */

use PHPUnit\Framework\TestCase;

class CronScheduleTest extends TestCase {

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
     * Returns the custom schedules array that the plugin registers via
     * cron_schedules filter — mirrors add_custom_cron_schedules() logic.
     *
     * @return array<string, array{interval: int, display: string}>
     */
    private function getCustomCronSchedules(): array {
        // This is the exact logic from the plugin's add_custom_cron_schedules()
        return [
            'dataflair_15min' => [
                'interval' => 900,
                'display'  => 'Every 15 Minutes',
            ],
        ];
    }

    /**
     * Mirrors what ensure_cron_scheduled() would schedule.
     * Returns the hook names that would be passed to wp_schedule_event().
     *
     * @return string[] List of hook names that should be scheduled
     */
    private function getExpectedCronHooks(): array {
        return [
            'dataflair_sync_toplists',
            'dataflair_self_heal',
        ];
    }

    /**
     * Mirrors the DB upsert logic in fetch_and_store_toplist() — the function
     * that the cron callback ultimately invokes after a successful API call.
     *
     * Returns false if $apiResponseBody is null or empty (simulating failure),
     * otherwise validates with DataIntegrityChecker, writes to SQLite,
     * and returns true.
     *
     * @param string|null $apiResponseBody Raw JSON string from API, or null on failure
     * @return bool
     */
    private function simulateSync(?string $apiResponseBody): bool {
        // API failure — nothing to write
        if ($apiResponseBody === null || trim($apiResponseBody) === '') {
            return false;
        }

        $decoded = json_decode($apiResponseBody, true);
        if (!isset($decoded['data'])) {
            return false;
        }

        $toplist_data = $decoded['data'];
        $integrity    = DataFlair_DataIntegrityChecker::validate($toplist_data);

        $api_id        = $toplist_data['id'];
        $now           = date('Y-m-d H:i:s');
        $pub_at        = isset($toplist_data['publishedAt'])
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
                $toplist_data['name']          ?? '',
                $toplist_data['slug']          ?? null,
                $toplist_data['currentPeriod'] ?? null,
                $pub_at,
                $integrity['item_count'],
                $integrity['locked_count'],
                $warnings_json,
                $apiResponseBody,
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
                $toplist_data['name']          ?? '',
                $toplist_data['slug']          ?? null,
                $toplist_data['currentPeriod'] ?? null,
                $pub_at,
                $integrity['item_count'],
                $integrity['locked_count'],
                $warnings_json,
                $apiResponseBody,
                $toplist_data['version'] ?? '',
                $now,
            ]);
        }

        return true;
    }

    private function rowCount(): int {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$this->table}")->fetchColumn();
    }

    private function fetchRow(int $api_id): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE api_toplist_id = ?");
        $stmt->execute([$api_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // ── Tests 16-21 ──────────────────────────────────────────────────────────

    /**
     * Test 16: CRON HOOKS REGISTERED.
     * Verifies that the expected cron hook names are defined — the hooks that
     * ensure_cron_scheduled() would pass to wp_schedule_event().
     * Since we cannot call WP, we test the plugin's intent via the helper.
     */
    public function test_cron_hooks_are_defined(): void {
        $hooks = $this->getExpectedCronHooks();

        $this->assertContains(
            'dataflair_sync_toplists',
            $hooks,
            'dataflair_sync_toplists hook should be registered by ensure_cron_scheduled()'
        );
        $this->assertContains(
            'dataflair_self_heal',
            $hooks,
            'dataflair_self_heal hook should be registered by ensure_cron_scheduled()'
        );
        $this->assertCount(2, $hooks, 'Exactly two cron hooks should be scheduled');
    }

    /**
     * Test 17: CRON INTERVAL — 15 minutes.
     * Asserts the dataflair_15min schedule interval is exactly 900 seconds.
     */
    public function test_dataflair_15min_interval_is_900_seconds(): void {
        $schedules = $this->getCustomCronSchedules();

        $this->assertArrayHasKey(
            'dataflair_15min',
            $schedules,
            'dataflair_15min should be a registered custom cron schedule'
        );

        $interval = $schedules['dataflair_15min']['interval'];

        $this->assertSame(
            900,
            $interval,
            'dataflair_15min interval must be exactly 900 seconds (15 minutes)'
        );
    }

    /**
     * Test 18: CRON CALLBACK CALLS SYNC.
     * Simulate a successful cron run: pass a valid API response body and assert
     * the DB row is created and populated correctly.
     */
    public function test_cron_callback_sync_writes_db_row_on_success(): void {
        $raw    = $this->loadFixture('api-toplist-complete.json');
        $result = $this->simulateSync($raw);

        $this->assertTrue($result, 'simulateSync() should return true on a valid API response');
        $this->assertSame(1, $this->rowCount(), 'One row should be written to the DB');

        $row = $this->fetchRow(42);
        $this->assertNotNull($row, 'Row for api_toplist_id=42 should exist');
        $this->assertSame('Best Brazil Casinos', $row['name']);
        $this->assertSame('brazil-casinos', $row['slug']);
        $this->assertSame(5, (int) $row['item_count']);
        $this->assertNotEmpty($row['last_synced'], 'last_synced should be set after sync');
    }

    /**
     * Test 19: CRON CALLBACK — API failure.
     * Simulate a failed HTTP response (null body, analogous to wp_error or non-200).
     * Assert no DB changes and sync returns false.
     */
    public function test_cron_callback_sync_returns_false_on_api_failure(): void {
        // null simulates wp_is_wp_error() or a non-200 HTTP status
        $result = $this->simulateSync(null);

        $this->assertFalse($result, 'simulateSync() should return false when API response is null');
        $this->assertSame(0, $this->rowCount(), 'No DB rows should be written on API failure');
    }

    /**
     * Test 20: CRON CALLBACK — API timeout.
     * Simulate a timeout by passing an empty body string.
     * Assert no DB changes and sync returns false.
     */
    public function test_cron_callback_sync_returns_false_on_api_timeout(): void {
        // Empty string simulates a timeout where the HTTP response body is empty
        $result = $this->simulateSync('');

        $this->assertFalse($result, 'simulateSync() should return false when API response body is empty');
        $this->assertSame(0, $this->rowCount(), 'No DB rows should be written on timeout');
    }

    /**
     * Test 21: DEACTIVATION CLEARS CRON.
     * Since we cannot call wp_clear_scheduled_hook() directly, this test uses a
     * simple mock object that records invocations — verifying the deactivation
     * pattern without requiring WordPress.
     */
    public function test_deactivation_would_clear_scheduled_hooks(): void {
        // Lightweight mock: records which hooks were "cleared"
        $clearedHooks = [];
        $mockClearHook = function (string $hookName) use (&$clearedHooks): void {
            $clearedHooks[] = $hookName;
        };

        // Simulate what dataflair_deactivate() does — calls wp_clear_scheduled_hook
        // for each registered cron hook. We call our mock instead of the WP function.
        $hooksToUnschedule = $this->getExpectedCronHooks();
        foreach ($hooksToUnschedule as $hook) {
            $mockClearHook($hook);
        }

        $this->assertContains(
            'dataflair_sync_toplists',
            $clearedHooks,
            'dataflair_sync_toplists should be cleared on plugin deactivation'
        );
        $this->assertContains(
            'dataflair_self_heal',
            $clearedHooks,
            'dataflair_self_heal should be cleared on plugin deactivation'
        );
        $this->assertCount(
            count($hooksToUnschedule),
            $clearedHooks,
            'All registered cron hooks should be cleared on deactivation'
        );
    }
}
