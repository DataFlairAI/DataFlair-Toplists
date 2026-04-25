<?php
/**
 * Phase 9 — Shim deprecation contract pin.
 *
 * Locks in the v2.1.0 behaviour of `DataFlair_Toplists::get_instance()`:
 *  - returns the same singleton on repeat calls (strangler-fig preserved)
 *  - strict deprecation is default-on in v2.1.0
 *  - the `dataflair_strict_deprecation` filter can silence it
 *  - notices de-duplicate per unique caller file/line per request
 *  - internal callers (files under DATAFLAIR_PLUGIN_DIR) are filtered out
 *    so hook-dispatch re-entry doesn't spam downstream error_log
 *
 * The test exercises the singleton via a private dispatcher that simulates
 * callers at controlled `file:line` positions so we can assert the guard
 * logic without loading the full 5,600-line plugin file.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ShimForwardingTestStubs.php';

final class ShimForwardingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        \ShimForwardingTestStubs::reset();
    }

    protected function tearDown(): void
    {
        \ShimForwardingTestStubs::reset();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_get_instance_returns_singleton(): void
    {
        Filters\expectApplied('dataflair_strict_deprecation')
            ->andReturnUsing(static fn($default) => false);

        $first  = \DataFlair_Toplists_Phase9_Shim::get_instance();
        $second = \DataFlair_Toplists_Phase9_Shim::get_instance();

        $this->assertSame($first, $second);
    }

    public function test_strict_deprecation_is_default_on(): void
    {
        // Assert that the filter is called with `true` as the default value —
        // that is the contract of the default-on flip in v2.1.0.
        $seenDefault = null;
        Filters\expectApplied('dataflair_strict_deprecation')
            ->andReturnUsing(static function ($default) use (&$seenDefault) {
                $seenDefault = $default;
                return false; // silence emission for this assertion
            });

        \DataFlair_Toplists_Phase9_Shim::get_instance();

        $this->assertTrue(
            $seenDefault,
            'v2.1.0 must pass the boolean true as the default to the strict-deprecation filter.'
        );
    }

    public function test_filter_can_silence_deprecation(): void
    {
        Filters\expectApplied('dataflair_strict_deprecation')
            ->andReturnUsing(static fn($default) => false);

        Functions\expect('_deprecated_function')->never();

        \DataFlair_Toplists_Phase9_Shim::callFromDownstream();

        $this->addToAssertionCount(1); // Mockery expectations verified on tearDown
    }

    public function test_notice_fires_once_per_downstream_caller(): void
    {
        Filters\expectApplied('dataflair_strict_deprecation')
            ->andReturnUsing(static fn($default) => $default);

        Functions\expect('_deprecated_function')
            ->once()
            ->with(
                'DataFlair_Toplists::get_instance',
                '2.0.0',
                '\\DataFlair\\Toplists\\Plugin::boot()'
            );

        \DataFlair_Toplists_Phase9_Shim::callFromDownstreamFileLine('/var/www/html/wp-content/themes/my-theme/inc.php', 42);
        \DataFlair_Toplists_Phase9_Shim::callFromDownstreamFileLine('/var/www/html/wp-content/themes/my-theme/inc.php', 42);

        $this->addToAssertionCount(1);
    }

    public function test_different_callers_each_fire_once(): void
    {
        Filters\expectApplied('dataflair_strict_deprecation')
            ->andReturnUsing(static fn($default) => $default);

        Functions\expect('_deprecated_function')->times(2);

        \DataFlair_Toplists_Phase9_Shim::callFromDownstreamFileLine('/var/www/a.php', 10);
        \DataFlair_Toplists_Phase9_Shim::callFromDownstreamFileLine('/var/www/b.php', 10);

        $this->addToAssertionCount(1);
    }

    public function test_internal_callers_are_filtered_out(): void
    {
        Filters\expectApplied('dataflair_strict_deprecation')
            ->andReturnUsing(static fn($default) => $default);

        Functions\expect('_deprecated_function')->never();

        $plugin_dir = defined('DATAFLAIR_PLUGIN_DIR') ? DATAFLAIR_PLUGIN_DIR : '/tmp/plugin/';
        \DataFlair_Toplists_Phase9_Shim::callFromDownstreamFileLine($plugin_dir . 'dataflair-toplists.php', 100);
        \DataFlair_Toplists_Phase9_Shim::callFromDownstreamFileLine($plugin_dir . 'src/Admin/Ajax/Foo.php', 50);

        $this->addToAssertionCount(1);
    }
}
