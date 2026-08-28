<?php
/**
 * Pins `wp dataflair sync`.
 *
 * The plugin ships no cron, so this is the only supported way to automate a
 * sync. It must page correctly, retry partial pages rather than skipping
 * them (skipping loses rows), stop on the first failure so cron can react,
 * and never loop forever on a broken pagination contract.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Cli;

use DataFlair\Toplists\Cli\SyncCommand;
use DataFlair\Toplists\Sync\SyncRequest;
use DataFlair\Toplists\Sync\SyncResult;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/SyncRequest.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/SyncResult.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Cli/SyncCommand.php';


/** Records what the command reported, via the injected reporter. */
final class FakeWpCli
{
    /** @var array<int, array{level: string, message: string}> */
    public static array $calls = [];

    public static function reset(): void { self::$calls = []; }
    public static function record(string $level, string $message): void
    {
        self::$calls[] = ['level' => $level, 'message' => $message];
    }

    /** @return array<int, string> */
    public static function levels(): array { return array_column(self::$calls, 'level'); }
    public static function text(): string { return implode("\n", array_column(self::$calls, 'message')); }
}

/** SyncCommand with the wait removed so backoff is testable instantly. */
final class NoWaitSyncCommand extends SyncCommand
{
    /** @var array<int, int> */
    public array $waits = [];

    protected function sleep(int $seconds): void
    {
        $this->waits[] = $seconds;
    }
}

final class SyncCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeWpCli::reset();
    }

    /** A stand-in sync service returning scripted results. */
    private function service(array $results): object
    {
        return new class($results) {
            /** @var array<int, SyncResult> */
            public array $results;
            /** @var array<int, int> */
            public array $pagesRequested = [];

            public function __construct(array $results)
            {
                $this->results = $results;
            }

            public function syncPage(SyncRequest $request): SyncResult
            {
                $this->pagesRequested[] = $request->page;
                return array_shift($this->results)
                    ?? SyncResult::success($request->page, 1, 0, 0, false, true);
            }
        };
    }

    private function invokeCommand(object $toplists, object $brands, array $assoc = []): void
    {
        (new SyncCommand(
            static fn() => $toplists,
            static fn() => $brands,
            static fn(string $level, string $message) => FakeWpCli::record($level, $message)
        ))([], $assoc);
    }

    public function test_rate_limited_page_is_retried_with_backoff_not_failed(): void
    {
        // A CLI run has none of the latency that paces the browser driver, so
        // it can trip a per-minute limit a browser sync never would. A cron
        // job can afford to wait.
        $toplists = $this->service([
            SyncResult::failure(1, 'Rate limited (429). Too many requests to the DataFlair API.'),
            SyncResult::success(1, 1, 5, 0, false, true),
        ]);

        $cmd = new NoWaitSyncCommand(
            static fn() => $toplists,
            static fn() => $this->service([]),
            static fn(string $level, string $message) => FakeWpCli::record($level, $message)
        );
        $cmd([], ['only' => 'toplists']);

        $this->assertSame([1, 1], $toplists->pagesRequested, 'the rate-limited page must be retried');
        $this->assertSame([20], $cmd->waits, 'first retry waits 20s');
        $this->assertContains('success', FakeWpCli::levels());
    }

    public function test_persistent_rate_limiting_eventually_fails_the_run(): void
    {
        $results = [];
        for ($i = 0; $i < 10; $i++) {
            $results[] = SyncResult::failure(1, 'Rate limited (429). Too many requests.');
        }
        $toplists = $this->service($results);

        $cmd = new NoWaitSyncCommand(
            static fn() => $toplists,
            static fn() => $this->service([]),
            static fn(string $level, string $message) => FakeWpCli::record($level, $message)
        );
        $cmd([], ['only' => 'toplists']);

        $this->assertSame([20, 40, 60], $cmd->waits, 'backoff is bounded');
        $this->assertContains('error', FakeWpCli::levels());
    }

    public function test_pages_through_every_page_of_both_streams(): void
    {
        $toplists = $this->service([
            SyncResult::success(1, 3, 5, 0, false, false),
            SyncResult::success(2, 3, 5, 0, false, false),
            SyncResult::success(3, 3, 5, 0, false, true),
        ]);
        $brands = $this->service([
            SyncResult::success(1, 2, 25, 0, false, false),
            SyncResult::success(2, 2, 25, 0, false, true),
        ]);

        $this->invokeCommand($toplists, $brands);

        $this->assertSame([1, 2, 3], $toplists->pagesRequested);
        $this->assertSame([1, 2], $brands->pagesRequested);
        $this->assertContains('success', FakeWpCli::levels(), 'a clean run must report success');
        $this->assertNotContains('error', FakeWpCli::levels());
    }

    public function test_partial_pages_are_retried_not_skipped(): void
    {
        // Skipping a partial page silently loses whatever it did not store.
        $toplists = $this->service([
            SyncResult::success(1, 2, 3, 0, true, false),   // partial, retry page 1
            SyncResult::success(1, 2, 2, 0, false, false),
            SyncResult::success(2, 2, 5, 0, false, true),
        ]);

        $this->invokeCommand($toplists, $this->service([SyncResult::success(1, 1, 1, 0, false, true)]));

        $this->assertSame([1, 1, 2], $toplists->pagesRequested);
    }

    public function test_stops_on_the_first_failure(): void
    {
        $toplists = $this->service([
            SyncResult::success(1, 5, 5, 0, false, false),
            SyncResult::failure(2, 'DataFlair contract canary: drift'),
        ]);
        $brands = $this->service([SyncResult::success(1, 1, 1, 0, false, true)]);

        $this->invokeCommand($toplists, $brands);

        $this->assertSame([1, 2], $toplists->pagesRequested, 'must not keep paging past a failure');
        $this->assertContains('error', FakeWpCli::levels(), 'a failed run must exit non-zero via WP_CLI::error');
        $this->assertStringContainsString('canary', FakeWpCli::text());
        $this->assertNotContains('success', FakeWpCli::levels());
    }

    public function test_a_skipped_page_is_reported_and_fails_the_run(): void
    {
        // Silent success on a skipped page would tell cron everything is
        // fine while part of the catalogue goes stale.
        $toplists = $this->service([
            SyncResult::success(1, 2, 0, 0, false, false, ['skipped' => true, 'next_page' => 2]),
            SyncResult::success(2, 2, 5, 0, false, true),
        ]);

        $this->invokeCommand($toplists, $this->service([SyncResult::success(1, 1, 1, 0, false, true)]));

        $this->assertContains('error', FakeWpCli::levels());
        $this->assertStringContainsString('skipped', FakeWpCli::text());
    }

    public function test_fallback_next_page_override_is_honoured(): void
    {
        // The per-ID fallback always reports partial but overrides next_page.
        // Trusting `partial` alone would re-request the same page forever.
        $toplists = $this->service([
            SyncResult::success(1, 3, 5, 0, true, false, ['fallback' => true, 'next_page' => 2]),
            SyncResult::success(2, 3, 5, 0, true, false, ['fallback' => true, 'next_page' => 3]),
            SyncResult::success(3, 3, 5, 0, false, true),
        ]);

        $this->invokeCommand($toplists, $this->service([SyncResult::success(1, 1, 1, 0, false, true)]));

        $this->assertSame([1, 2, 3], $toplists->pagesRequested);
    }

    public function test_is_complete_terminates_even_when_partial(): void
    {
        $toplists = $this->service([
            SyncResult::success(1, 4, 0, 0, true, true, ['budget_skip' => true, 'next_page' => 2]),
        ]);

        $this->invokeCommand($toplists, $this->service([SyncResult::success(1, 1, 1, 0, false, true)]));

        $this->assertSame([1], $toplists->pagesRequested, 'is_complete must end the stream');
    }

    public function test_a_dead_endpoint_does_not_walk_the_page_bound_forward(): void
    {
        // The fallback synthesises last_page as page+1 when it cannot learn
        // the real total; re-reading it every iteration would never terminate.
        $results = [];
        for ($p = 1; $p <= 60; $p++) {
            $results[] = SyncResult::success($p, $p + 1, 0, 0, false, false, ['skipped' => true, 'next_page' => $p + 1]);
        }
        $toplists = $this->service($results);

        $this->invokeCommand($toplists, $this->service([]), ['only' => 'toplists']);

        $this->assertSame([1, 2], $toplists->pagesRequested, 'page bound must be fixed from the first response');
    }

    public function test_only_flag_restricts_the_stream(): void
    {
        $toplists = $this->service([SyncResult::success(1, 1, 5, 0, false, true)]);
        $brands   = $this->service([SyncResult::success(1, 1, 5, 0, false, true)]);

        $this->invokeCommand($toplists, $brands, ['only' => 'toplists']);

        $this->assertSame([1], $toplists->pagesRequested);
        $this->assertSame([], $brands->pagesRequested, 'brands must not run with --only=toplists');
    }

    public function test_guard_stops_an_endless_partial_loop(): void
    {
        // A backend that always reports "partial" must not spin forever.
        $always = [];
        for ($i = 0; $i < 600; $i++) {
            $always[] = SyncResult::success(1, 10, 0, 0, true, false);
        }
        $toplists = $this->service($always);

        $this->invokeCommand($toplists, $this->service([]), ['only' => 'toplists']);

        $this->assertLessThanOrEqual(500, count($toplists->pagesRequested));
        $this->assertGreaterThan(1, count($toplists->pagesRequested));
        $this->assertContains('error', FakeWpCli::levels(), 'hitting the guard is a failure, not a silent stop');
    }
}
