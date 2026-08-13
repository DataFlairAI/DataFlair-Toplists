<?php
/**
 * Phase 9.6 (admin UX redesign) — pins SyncHistoryRecorder behavior.
 *
 * Verifies that the recorder writes capped FIFO entries to the
 * `dataflair_sync_history` option in response to the existing sync action
 * hooks emitted by Brand/Toplist sync services. Since 0772bfd, per-page
 * totals accumulate in a `dataflair_sync_acc_{type}` transient and only
 * flush to a single history entry once the payload reports `is_complete`
 * (or `partial`) — not one entry per page.
 *
 * get_option/update_option/*_transient are stubbed via the shared
 * SyncFunctionStubs.php (see its docblock) rather than Brain Monkey:
 * RenderReadOnlyStubs.php (Integration suite) declares real global versions
 * of these, and once loaded in the same process Patchwork can no longer
 * redefine them for global stubbing.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Sync;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DataFlair\Toplists\Sync\SyncHistoryRecorder;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SyncFunctionStubs.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/SyncHistoryRecorder.php';

final class SyncHistoryRecorderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        \SyncFunctionStubsStore::reset();
        Functions\when('add_action')->justReturn(true);
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

        $entries = \SyncFunctionStubsStore::$options[SyncHistoryRecorder::OPTION_KEY] ?? [];
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
        $this->assertArrayNotHasKey(SyncHistoryRecorder::OPTION_KEY, \SyncFunctionStubsStore::$options);

        $rec->onBatchFinished([
            'type'            => 'brands',
            'page'            => 2,
            'items_done'      => 5,
            'errors'          => 0,
            'partial'         => false,
            'is_complete'     => true,
            'elapsed_seconds' => 1.5,
        ]);

        $entries = \SyncFunctionStubsStore::$options[SyncHistoryRecorder::OPTION_KEY];
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

        $entries = \SyncFunctionStubsStore::$options[SyncHistoryRecorder::OPTION_KEY];
        $this->assertSame('partial', $entries[0]['status']);
        $this->assertStringContainsString('2 errors', $entries[0]['detail']);
    }

    public function test_page_level_partial_retry_does_not_flush_until_run_completes(): void
    {
        $rec = new SyncHistoryRecorder();

        // Page 1: normal, mid-run.
        $rec->onBatchFinished([
            'type' => 'brands', 'page' => 1, 'items_done' => 10, 'errors' => 0,
            'partial' => false, 'is_complete' => false, 'elapsed_seconds' => 2.0,
        ]);
        // Page 2 hits its wall-clock budget — a retry signal (next_page
        // stays on page 2), NOT the run ending. Must not flush yet.
        $rec->onBatchFinished([
            'type' => 'brands', 'page' => 2, 'items_done' => 3, 'errors' => 0,
            'partial' => true, 'is_complete' => false, 'elapsed_seconds' => 25.0,
        ]);
        $this->assertArrayNotHasKey(SyncHistoryRecorder::OPTION_KEY, \SyncFunctionStubsStore::$options);

        // Page 2 retried, succeeds this time.
        $rec->onBatchFinished([
            'type' => 'brands', 'page' => 2, 'items_done' => 5, 'errors' => 0,
            'partial' => false, 'is_complete' => false, 'elapsed_seconds' => 3.0,
        ]);
        $this->assertArrayNotHasKey(SyncHistoryRecorder::OPTION_KEY, \SyncFunctionStubsStore::$options);

        // Page 3: the run genuinely completes.
        $rec->onBatchFinished([
            'type' => 'brands', 'page' => 3, 'items_done' => 7, 'errors' => 0,
            'partial' => false, 'is_complete' => true, 'elapsed_seconds' => 1.5,
        ]);

        $entries = \SyncFunctionStubsStore::$options[SyncHistoryRecorder::OPTION_KEY];
        $this->assertCount(1, $entries, 'The whole run must produce exactly one history entry.');
        $this->assertSame('success', $entries[0]['status']);
        // Totals span every call including the partial retry: 10+3+5+7=25.
        $this->assertStringContainsString('25 synced', $entries[0]['title']);
    }

    public function test_batch_that_is_both_terminal_and_budget_limited_reports_partial(): void
    {
        // ToplistSyncService's "budget already spent" fallback branch can
        // report partial=true and is_complete=true together (this page IS
        // the last page, but budget ran out before any items were synced
        // this call) — the flush must still happen (is_complete=true) and
        // must still be labelled 'partial', not 'success'.
        $rec = new SyncHistoryRecorder();
        $rec->onBatchFinished([
            'type' => 'toplists', 'page' => 5, 'items_done' => 0, 'errors' => 0,
            'partial' => true, 'is_complete' => true, 'elapsed_seconds' => 0.0,
        ]);

        $entries = \SyncFunctionStubsStore::$options[SyncHistoryRecorder::OPTION_KEY];
        $this->assertCount(1, $entries);
        $this->assertSame('partial', $entries[0]['status']);
    }

    public function test_item_failed_records_error_entry(): void
    {
        $rec = new SyncHistoryRecorder();
        $rec->onItemFailed([
            'type'  => 'brands',
            'page'  => 2,
            'error' => 'HTTP 500: upstream',
        ]);

        $entries = \SyncFunctionStubsStore::$options[SyncHistoryRecorder::OPTION_KEY];
        $this->assertCount(1, $entries);
        $this->assertSame('error', $entries[0]['status']);
        $this->assertSame('HTTP 500: upstream', $entries[0]['detail']);
    }

    public function test_unknown_type_is_ignored(): void
    {
        $rec = new SyncHistoryRecorder();
        $rec->onBatchFinished(['type' => 'unknown', 'page' => 1]);
        $rec->onItemFailed(['type' => 'unknown', 'page' => 1, 'error' => 'x']);

        $this->assertSame([], \SyncFunctionStubsStore::$options[SyncHistoryRecorder::OPTION_KEY] ?? []);
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

        $entries = \SyncFunctionStubsStore::$options[SyncHistoryRecorder::OPTION_KEY];
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
