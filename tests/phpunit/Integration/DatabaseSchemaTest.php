<?php
/**
 * Integration tests for database schema upgrade (tests 30-33).
 *
 * Uses SQLite in-memory database to simulate ALTER TABLE operations.
 * Verifies new v1.5 columns are added without data loss, and the
 * upgrade is idempotent (safe to run twice).
 */

use PHPUnit\Framework\TestCase;

class DatabaseSchemaTest extends TestCase {

    private PDO    $pdo;
    private string $table = 'wp_dataflair_toplists';

    protected function setUp(): void {
        parent::setUp();
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    private function createLegacyTable(): void {
        $this->pdo->exec("
            CREATE TABLE {$this->table} (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                api_toplist_id INTEGER NOT NULL UNIQUE,
                name           TEXT NOT NULL,
                data           TEXT NOT NULL,
                version        TEXT DEFAULT NULL,
                last_synced    TEXT NOT NULL
            )
        ");
    }

    private function runSchemaUpgrade(): void {
        $existing = $this->getColumnNames();
        $map = [
            'slug'           => "ALTER TABLE {$this->table} ADD COLUMN slug TEXT DEFAULT NULL",
            'current_period' => "ALTER TABLE {$this->table} ADD COLUMN current_period TEXT DEFAULT NULL",
            'published_at'   => "ALTER TABLE {$this->table} ADD COLUMN published_at TEXT DEFAULT NULL",
            'item_count'     => "ALTER TABLE {$this->table} ADD COLUMN item_count INTEGER DEFAULT 0",
            'locked_count'   => "ALTER TABLE {$this->table} ADD COLUMN locked_count INTEGER DEFAULT 0",
            'sync_warnings'  => "ALTER TABLE {$this->table} ADD COLUMN sync_warnings TEXT DEFAULT NULL",
        ];
        foreach ($map as $col => $sql) {
            if (!in_array($col, $existing)) {
                $this->pdo->exec($sql);
            }
        }
    }

    private function getColumnNames(): array {
        $stmt = $this->pdo->query("PRAGMA table_info({$this->table})");
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name');
    }

    private function hasColumn(string $col): bool {
        return in_array($col, $this->getColumnNames());
    }

    /** Test 30: All new v1.5 columns exist after upgrade */
    public function test_upgrade_adds_all_required_columns(): void {
        $this->createLegacyTable();
        $this->runSchemaUpgrade();

        $this->assertTrue($this->hasColumn('slug'),           'slug column should exist');
        $this->assertTrue($this->hasColumn('current_period'), 'current_period column should exist');
        $this->assertTrue($this->hasColumn('published_at'),   'published_at column should exist');
        $this->assertTrue($this->hasColumn('item_count'),     'item_count column should exist');
        $this->assertTrue($this->hasColumn('locked_count'),   'locked_count column should exist');
        $this->assertTrue($this->hasColumn('sync_warnings'),  'sync_warnings column should exist');
    }

    /** Test 31: Schema upgrade is idempotent — safe to run twice */
    public function test_upgrade_is_idempotent(): void {
        $this->createLegacyTable();
        $this->runSchemaUpgrade();
        $this->runSchemaUpgrade(); // second run: all columns already exist, no-op

        $this->assertTrue($this->hasColumn('slug'));
        $this->assertTrue($this->hasColumn('item_count'));
        $this->assertTrue($this->hasColumn('sync_warnings'));
    }

    /** Test 32: Existing data is preserved after upgrade */
    public function test_upgrade_preserves_existing_data(): void {
        $this->createLegacyTable();

        $this->pdo->exec(
            "INSERT INTO {$this->table} (api_toplist_id, name, data, version, last_synced)
             VALUES (99, 'Old Toplist', '{\"data\":{\"id\":99}}', '1.0', '2026-01-01 00:00:00')"
        );

        $this->runSchemaUpgrade();

        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE api_toplist_id = ?");
        $stmt->execute([99]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotNull($row, 'Existing row should survive upgrade');
        $this->assertSame('Old Toplist', $row['name']);
        $this->assertNull($row['slug']);
        $this->assertSame(0, (int) $row['item_count']);
    }

    /** Test 33: Slug column enables lookup by slug */
    public function test_slug_lookup_returns_correct_row(): void {
        $this->createLegacyTable();
        $this->runSchemaUpgrade();

        $this->pdo->exec(
            "INSERT INTO {$this->table} (api_toplist_id, name, slug, data, version, last_synced)
             VALUES (42, 'Brazil Casinos', 'brazil-casinos', '{\"data\":{\"id\":42}}', '1.0', '2026-03-01 00:00:00')"
        );
        $this->pdo->exec(
            "INSERT INTO {$this->table} (api_toplist_id, name, slug, data, version, last_synced)
             VALUES (43, 'UK Casinos', 'uk-casinos', '{\"data\":{\"id\":43}}', '1.0', '2026-03-01 00:00:00')"
        );

        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE slug = ? LIMIT 1");
        $stmt->execute(['brazil-casinos']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotNull($row);
        $this->assertSame(42, (int) $row['api_toplist_id']);
        $this->assertSame('brazil-casinos', $row['slug']);
    }
}
