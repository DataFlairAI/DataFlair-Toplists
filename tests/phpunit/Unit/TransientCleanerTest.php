<?php
/**
 * Phase 9.10 — Pins TransientCleaner behaviour: chunked DELETE loop
 * across both transient + timeout patterns, optional WallClockBudget
 * bail-out.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Sync;

use DataFlair\Toplists\Support\WallClockBudget;
use DataFlair\Toplists\Sync\TransientCleaner;
use Mockery as M;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'includes/Support/WallClockBudget.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/TransientCleaner.php';

final class TransientCleanerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb = M::mock('wpdb');
        $wpdb->options = 'wp_options';
        $wpdb->shouldReceive('prepare')->andReturnUsing(static function ($sql, ...$args) {
            return $sql;
        });
    }

    protected function tearDown(): void
    {
        M::close();
        parent::tearDown();
    }

    public function test_loops_through_both_patterns_and_sums_deletions(): void
    {
        global $wpdb;
        // First pattern: 1000, 100 (short → done). Second: 250 (short → done).
        // Total = 1350.
        $wpdb->shouldReceive('query')->times(3)->andReturn(1000, 100, 250);

        $cleaner = new TransientCleaner();
        $this->assertSame(1350, $cleaner->clear());
    }

    public function test_bails_when_budget_exceeded(): void
    {
        global $wpdb;
        // Budget is exhausted before the very first query — the inner
        // while-loop should break immediately for both patterns. Zero
        // queries should fire.
        $wpdb->shouldNotReceive('query');

        $budget = new WallClockBudget(0.0);
        usleep(2000); // ensure exceeded(1.0) returns true

        $cleaner = new TransientCleaner();
        $this->assertSame(0, $cleaner->clear($budget));
    }

    public function test_breaks_loop_on_query_failure(): void
    {
        global $wpdb;
        // First pattern returns false → break inner loop → next pattern.
        // Second pattern returns 0 → outer loop ends. Total = 0.
        $wpdb->shouldReceive('query')->times(2)->andReturn(false, 0);

        $cleaner = new TransientCleaner();
        $this->assertSame(0, $cleaner->clear());
    }
}
