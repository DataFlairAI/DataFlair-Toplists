<?php
/**
 * Phase 9.11 — Pins Support\RelativeTimeFormatter outputs across all
 * branches of timeAgo() and timeUntil(). The formatter calls time()
 * internally, so we anchor the test against time() and offset.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Support;

use DataFlair\Toplists\Support\RelativeTimeFormatter;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Support/RelativeTimeFormatter.php';

final class RelativeTimeFormatterTest extends TestCase
{
    private RelativeTimeFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new RelativeTimeFormatter();
    }

    public function test_time_ago_under_10_seconds_returns_just_now(): void
    {
        $this->assertSame('just now', $this->formatter->timeAgo(time() - 5));
    }

    public function test_time_ago_under_minute_returns_seconds_label(): void
    {
        $this->assertSame('30 seconds ago', $this->formatter->timeAgo(time() - 30));
    }

    public function test_time_ago_singular_minute_label(): void
    {
        $this->assertSame('1 minute ago', $this->formatter->timeAgo(time() - 90));
    }

    public function test_time_ago_plural_minutes_label(): void
    {
        $this->assertSame('5 minutes ago', $this->formatter->timeAgo(time() - 300));
    }

    public function test_time_ago_singular_hour_label(): void
    {
        $this->assertSame('1 hour ago', $this->formatter->timeAgo(time() - 4000));
    }

    public function test_time_ago_plural_hours_label(): void
    {
        $this->assertSame('3 hours ago', $this->formatter->timeAgo(time() - (3 * 3600 + 60)));
    }

    public function test_time_ago_beyond_a_day_returns_date_string(): void
    {
        $past = time() - (3 * 86400);
        $this->assertSame(date('Y-m-d H:i', $past), $this->formatter->timeAgo($past));
    }

    public function test_time_until_zero_or_negative_returns_any_moment(): void
    {
        $this->assertSame('any moment', $this->formatter->timeUntil(time() - 5));
        $this->assertSame('any moment', $this->formatter->timeUntil(time()));
    }

    public function test_time_until_seconds_label(): void
    {
        $this->assertSame('in 30 seconds', $this->formatter->timeUntil(time() + 30));
    }

    public function test_time_until_singular_minute_label(): void
    {
        $this->assertSame('in 1 minute', $this->formatter->timeUntil(time() + 90));
    }

    public function test_time_until_plural_minutes_label(): void
    {
        $this->assertSame('in 5 minutes', $this->formatter->timeUntil(time() + 300));
    }

    public function test_time_until_singular_hour_label(): void
    {
        $this->assertSame('in 1 hour', $this->formatter->timeUntil(time() + 4000));
    }

    public function test_time_until_plural_hours_label(): void
    {
        $this->assertSame('in 3 hours', $this->formatter->timeUntil(time() + (3 * 3600 + 60)));
    }
}
