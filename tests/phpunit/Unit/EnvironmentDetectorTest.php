<?php
/**
 * Phase 9.11 — Smoke-tests Support\EnvironmentDetector.
 *
 * The detector inspects /.dockerenv, /proc/1/cgroup, and DNS for
 * host.docker.internal — none of which can be mocked without a
 * filesystem/DNS stub layer. We validate the contract: the method
 * returns a strict bool and never throws regardless of the host's
 * actual containerisation state.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Support;

use DataFlair\Toplists\Support\EnvironmentDetector;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Support/EnvironmentDetector.php';

final class EnvironmentDetectorTest extends TestCase
{
    public function test_returns_bool_on_any_host(): void
    {
        $detector = new EnvironmentDetector();
        $result   = $detector->isRunningInDocker();
        $this->assertIsBool($result);
    }

    public function test_consecutive_calls_are_idempotent(): void
    {
        $detector = new EnvironmentDetector();
        $first    = $detector->isRunningInDocker();
        $second   = $detector->isRunningInDocker();
        $this->assertSame($first, $second);
    }
}
