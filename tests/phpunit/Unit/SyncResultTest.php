<?php
/**
 * Phase 3 — pins the immutable SyncResult value-object shape. The AJAX admin JS
 * depends on this exact payload, so it needs a regression test before every
 * future phase touches the sync path.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Sync;

use DataFlair\Toplists\Sync\SyncResult;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/SyncResult.php';

final class SyncResultTest extends TestCase
{
    public function test_success_non_partial_advances_next_page(): void
    {
        $r = SyncResult::success(2, 5, 10, 0, false, false);

        $this->assertTrue($r->success);
        $this->assertSame(2, $r->page);
        $this->assertSame(5, $r->lastPage);
        $this->assertSame(3, $r->nextPage);
        $this->assertFalse($r->partial);
        $this->assertFalse($r->isComplete);
    }

    public function test_success_partial_keeps_same_page_for_retry(): void
    {
        $r = SyncResult::success(4, 6, 3, 1, true, false);

        $this->assertSame(4, $r->nextPage);
        $this->assertTrue($r->partial);
    }

    public function test_failure_shape_matches_legacy_ajax_error(): void
    {
        $r = SyncResult::failure(2, 'JSON decode error: bad syntax');

        $this->assertFalse($r->success);
        $this->assertSame(['message' => 'JSON decode error: bad syntax'], $r->toArray());
    }

    public function test_success_to_array_matches_legacy_keys(): void
    {
        $r = SyncResult::success(
            3,
            5,
            10,
            1,
            false,
            false,
            ['total_synced' => 47, 'total_brands' => 100]
        );

        $out = $r->toArray();

        $this->assertSame(3, $out['page']);
        $this->assertSame(5, $out['last_page']);
        $this->assertSame(10, $out['synced']);
        $this->assertSame(1, $out['errors']);
        $this->assertFalse($out['partial']);
        $this->assertSame(4, $out['next_page']);
        $this->assertFalse($out['is_complete']);
        $this->assertSame(47, $out['total_synced']);
        $this->assertSame(100, $out['total_brands']);
    }

    public function test_extra_keys_can_override_defaults_for_fallback_path(): void
    {
        // Per-ID fallback returns skipped/skip_reason and forces next_page override.
        $r = SyncResult::success(
            2,
            5,
            0,
            0,
            false,
            false,
            ['skipped' => true, 'skip_reason' => 'every split 500d', 'fallback' => true, 'next_page' => 3]
        );

        $out = $r->toArray();

        $this->assertTrue($out['skipped']);
        $this->assertTrue($out['fallback']);
        $this->assertSame('every split 500d', $out['skip_reason']);
        $this->assertSame(3, $out['next_page']);
    }

    public function test_properties_are_readonly(): void
    {
        $r = SyncResult::success(1, 1, 0, 0, false, true);

        $this->expectException(\Error::class);
        $r->page = 99;
    }
}
