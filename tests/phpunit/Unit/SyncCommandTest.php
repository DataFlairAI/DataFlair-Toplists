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

final class SyncCommandTest extends TestCase
{
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
            static fn() => $brands
        ))([], $assoc);
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
    }
}
