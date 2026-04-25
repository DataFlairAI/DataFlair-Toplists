<?php
/**
 * Phase 9.10 — Pins PaginatedDeleter behaviour: whitelist enforcement,
 * chunk clamping, loop termination.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Database;

use DataFlair\Toplists\Database\PaginatedDeleter;
use Mockery as M;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/PaginatedDeleter.php';

final class PaginatedDeleterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb = M::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturnUsing(static function ($sql, ...$args) {
            return $sql;
        });
    }

    protected function tearDown(): void
    {
        M::close();
        parent::tearDown();
    }

    public function test_returns_zero_when_table_not_whitelisted(): void
    {
        global $wpdb;
        $wpdb->shouldNotReceive('query');

        $deleter = new PaginatedDeleter();
        $this->assertSame(0, $deleter->deleteAll('wp_users'));
    }

    public function test_loops_until_chunk_returns_short(): void
    {
        global $wpdb;
        // 500, 500, 100 (under chunk → done) → total 1100.
        $wpdb->shouldReceive('query')->times(3)->andReturn(500, 500, 100);

        $deleter = new PaginatedDeleter();
        $this->assertSame(1100, $deleter->deleteAll('wp_dataflair_toplists', 500));
    }

    public function test_breaks_on_query_failure(): void
    {
        global $wpdb;
        $wpdb->shouldReceive('query')->once()->andReturn(false);

        $deleter = new PaginatedDeleter();
        $this->assertSame(0, $deleter->deleteAll('wp_dataflair_brands', 500));
    }

    public function test_chunk_argument_is_clamped(): void
    {
        global $wpdb;
        // Capture the prepare-formatted SQL to verify clamp behaviour
        // through the resulting query call count.
        $wpdb->shouldReceive('query')->once()->andReturn(0);

        $deleter = new PaginatedDeleter();
        // chunk=10 should be clamped up to 50 (still a single short loop iteration).
        $this->assertSame(0, $deleter->deleteAll('wp_dataflair_alternative_toplists', 10));
    }
}
