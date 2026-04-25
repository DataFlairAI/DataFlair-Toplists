<?php
/**
 * Phase 9.9 — Pins SyncLabelFormatter option-name fallback + relative-time output.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Frontend\Render;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DataFlair\Toplists\Frontend\Render\SyncLabelFormatter;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Frontend/Render/SyncLabelFormatter.php';

final class SyncLabelFormatterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_returns_never_when_option_missing(): void
    {
        Functions\when('get_option')->justReturn(false);

        $out = (new SyncLabelFormatter())->format('dataflair_last_toplist_sync');

        $this->assertSame('Last sync: never', $out);
    }

    public function test_falls_back_from_new_name_to_legacy_cron_run(): void
    {
        $now = time();
        Functions\expect('get_option')
            ->once()
            ->with('dataflair_last_toplist_sync')
            ->andReturn(false);
        Functions\expect('get_option')
            ->once()
            ->with('dataflair_last_toplist_cron_run')
            ->andReturn($now - 30);

        $out = (new SyncLabelFormatter())->format('dataflair_last_toplist_sync');

        $this->assertStringStartsWith('Last sync: ', $out);
        $this->assertStringContainsString('seconds ago', $out);
    }

    public function test_falls_back_from_legacy_cron_run_to_new_name(): void
    {
        $now = time();
        Functions\expect('get_option')
            ->once()
            ->with('dataflair_last_brands_cron_run')
            ->andReturn(false);
        Functions\expect('get_option')
            ->once()
            ->with('dataflair_last_brands_sync')
            ->andReturn($now - 30);

        $out = (new SyncLabelFormatter())->format('dataflair_last_brands_cron_run');

        $this->assertStringContainsString('seconds ago', $out);
    }

    public function test_just_now_under_ten_seconds(): void
    {
        Functions\when('get_option')->justReturn(time() - 2);

        $out = (new SyncLabelFormatter())->format('dataflair_last_toplist_sync');

        $this->assertSame('Last sync: just now', $out);
    }

    public function test_minutes_ago_under_an_hour(): void
    {
        Functions\when('get_option')->justReturn(time() - 600);

        $out = (new SyncLabelFormatter())->format('dataflair_last_toplist_sync');

        $this->assertStringContainsString('minutes ago', $out);
    }

    public function test_falls_through_to_full_date_for_old_runs(): void
    {
        Functions\when('get_option')->justReturn(time() - 86400 * 2);

        $out = (new SyncLabelFormatter())->format('dataflair_last_toplist_sync');

        $this->assertMatchesRegularExpression('/Last sync: \d{4}-\d{2}-\d{2}/', $out);
    }
}
