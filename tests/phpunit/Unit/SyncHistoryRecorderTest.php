<?php
/**
 * Phase 9.6 (admin UX redesign) — pins SyncHistoryRecorder behavior.
 *
 * Verifies that the recorder writes capped FIFO entries to the
 * `dataflair_sync_history` option in response to the existing sync action
 * hooks emitted by Brand/Toplist sync services. Since 0772bfd, per-page
 * totals accumulate in a `dataflair_sync_acc_{type}` transient and only
 * flush to a single history entry once the payload reports
 * `is_complete` (or `partial`) — not one entry per page.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Sync;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DataFlair\Toplists\Sync\SyncHistoryRecorder;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/SyncHistoryRecorder.php';

final class SyncHistoryRecorderTest extends TestCase
{
    /** @var array<string, mixed> */
    public static array $store = [];

    /** @var array<string, mixed> */
    public static array $transients = [];

    public static function peek(string $key, $default)
    {
        return self::$store[$key] ?? $default;
    }

    public static function poke(string $key, $value): void
    {
        self::$store[$key] = $value;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        self::$store = [];
        self::$transients = [];
        Functions\when('get_option')->alias(function ($key, $default = []) {
            return SyncHistoryRecorderTest::peek($key, $default);
        });
        Functions\when('update_option')->alias(function ($key, $value, $autoload = null) {
            SyncHistoryRecorderTest::poke($key, $value);
            return true;
        });
        Functions\when('add_action')->justReturn(true);
        // Real in-memory transient store — SyncHistoryRecorder accumulates
        // per-page totals here between dataflair_sync_batch_finished calls
        // until the batch reports is_complete/partial.
        Functions\when('get_transient')->alias(function ($key) {
            return SyncHistoryRecorderTest::$transients[$key] ?? false;
        });
        Functions\when('set_transient')->alias(function ($key, $value, $ttl = 0) {
            SyncHistoryRecorderTest::$transients[$key] = $value;
            return true;
        });
        Functions\when('delete_transient')->alias(function ($key) {
            unset(SyncHistoryRecorderTest::$transients[$key]);
            return true;
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_batch_finished_writes_success_entry(): void
    {
        $rec = new SyncHistoryRecorder();
        $rec->onBatchFinished([
            'type'            => 'brands',
            'page'            => 1,
            'items_done'      => 12,
            'errors'          => 0,
            'partial'         => false,
            'is_complete'     => true,
            'elapsed_seconds' => 4.21,
        ]);

        $entries = self::$store[SyncHistoryRecorder::OPTION_KEY] ?? [];
        $this->assertCount(1, $entries);
        $this->assertSame('success', $entries[0]['status']);
        $this->assertSame('brands', $entries[0]['source']);
        $this->assertStringContainsString('12 synced', $entries[0]['title']);
        $this->assertStringContainsString('1 page', $entries[0]['title']);
        $this->assertStringContainsString('0 errors', $entries[0]['detail']);
    }

    public function test_multi_page_batch_accumulates_before_flushing_on_complete(): void
    {
        $rec = new SyncHistoryRecorder();

        $rec->onBatchFinished([
            'type'            => 'brands',
            'page'            => 1,
            'items_done'      => 10,
            'errors'          => 1,
            'partial'         => false,
            'is_complete'     => false,
            'elapsed_seconds' => 2.0,
        ]);
        // Still mid-batch — accumulator persisted to a transient, no history entry yet.
        $this->assertArrayNotHasKey(SyncHistoryRecorder::OPTION_KEY, self::$store);

        $rec->onBatchFinished([
            'type'            => 'brands',
            'page'            => 2,
            'items_done'      => 5,
            'errors'          => 0,
            'partial'         => false,
            'is_complete'     => true,
            'elapsed_seconds' => 1.5,
        ]);

        $entries = self::$store[SyncHistoryRecorder::OPTION_KEY];
        $this->assertCount(1, $entries);
        // Totals span both pages: 10+5 synced, 1+0 errors, 2 pages.
        $this->assertSame('partial', $entries[0]['status']);
        $this->assertStringContainsString('15 synced', $entries[0]['title']);
        $this->assertStringContainsString('2 pages', $entries[0]['title']);
        $this->assertStringContainsString('1 error', $entries[0]['detail']);
    }

    public function test_batch_with_errors_records_partial_status(): void
    {
        $rec = new SyncHistoryRecorder();
        $rec->onBatchFinished([
            'type'            => 'toplists',
            'page'            => 1,
            'items_done'      => 9,
            'errors'          => 2,
            'partial'         => false,
            'is_complete'     => true,
            'elapsed_seconds' => 1.0,
        ]);

        $entries = self::$store[SyncHistoryRecorder::OPTION_KEY];
        $this->assertSame('partial', $entries[0]['status']);
        $this->assertStringContainsString('2 errors', $entries[0]['detail']);
    }

    public function test_item_failed_records_error_entry(): void
    {
        $rec = new SyncHistoryRecorder();
        $rec->onItemFailed([
            'type'  => 'brands',
            'page'  => 2,
            'error' => 'HTTP 500: upstream',
        ]);

        $entries = self::$store[SyncHistoryRecorder::OPTION_KEY];
        $this->assertCount(1, $entries);
        $this->assertSame('error', $entries[0]['status']);
        $this->assertSame('HTTP 500: upstream', $entries[0]['detail']);
    }

    public function test_unknown_type_is_ignored(): void
    {
        $rec = new SyncHistoryRecorder();
        $rec->onBatchFinished(['type' => 'unknown', 'page' => 1]);
        $rec->onItemFailed(['type' => 'unknown', 'page' => 1, 'error' => 'x']);

        $this->assertSame([], self::$store[SyncHistoryRecorder::OPTION_KEY] ?? []);
    }

    public function test_history_is_capped_fifo_newest_first(): void
    {
        $rec = new SyncHistoryRecorder();
        $cap = SyncHistoryRecorder::MAX_ENTRIES;

        // Each iteration is its own complete single-page batch (page=1 +
        // is_complete=true) so every call flushes a distinct history entry.
        // items_done varies per iteration so entries stay distinguishable —
        // the title no longer echoes the raw page number since 0772bfd.
        for ($i = 1; $i <= $cap + 5; $i++) {
            $rec->onBatchFinished([
                'type'        => 'brands',
                'page'        => 1,
                'items_done'  => $i,
                'errors'      => 0,
                'partial'     => false,
                'is_complete' => true,
                'elapsed_seconds' => 0.1,
            ]);
        }

        $entries = self::$store[SyncHistoryRecorder::OPTION_KEY];
        $this->assertCount($cap, $entries);
        // Newest first — last pushed had items_done = (cap+5).
        $this->assertStringContainsString(($cap + 5) . ' synced', $entries[0]['title']);
        // Oldest in window: items_done = 6 (entries 1-5 dropped).
        $this->assertStringContainsString('6 synced', $entries[$cap - 1]['title']);
    }

    public function test_recent_returns_top_n(): void
    {
        $rec = new SyncHistoryRecorder();
        for ($i = 1; $i <= 7; $i++) {
            $rec->onBatchFinished([
                'type' => 'brands', 'page' => 1, 'items_done' => 1,
                'errors' => 0, 'partial' => false, 'is_complete' => true,
                'elapsed_seconds' => 0.1,
            ]);
        }

        $this->assertCount(5, $rec->recent(5));
        $this->assertCount(0, $rec->recent(0));
        $this->assertCount(7, $rec->recent(50));
    }
}
