<?php
/**
 * Phase 9.6 (admin UX redesign) — pins RunTestHandler contract.
 *
 * Verifies: rejects empty/unknown slugs; dispatches known slugs to
 * TestsRunner and returns structured results.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Admin\Ajax;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DataFlair\Toplists\Admin\Ajax\RunTestHandler;
use DataFlair\Toplists\Admin\Pages\Tools\TestsRunner;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/AjaxHandlerInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Pages/Tools/TestsRunner.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Ajax/RunTestHandler.php';

final class RunTestHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('sanitize_key')->alias(static fn(string $v) => preg_replace('/[^a-z0-9_\-]/', '', strtolower($v)));
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_rejects_empty_slug(): void
    {
        $handler = new RunTestHandler(new TestsRunner());

        $result = $handler->handle([]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unknown', $result['data']['message']);
    }

    public function test_rejects_unknown_slug(): void
    {
        $handler = new RunTestHandler(new TestsRunner());

        $result = $handler->handle(['slug' => 'not_a_real_test']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unknown', $result['data']['message']);
    }

    public function test_runs_known_slug_and_returns_result_shape(): void
    {
        Functions\when('get_option')->alias(static fn(string $key, $default = false) => match ($key) {
            'dataflair_db_version' => '1.12',
            TestsRunner::OPTION_KEY => [],
            default                 => $default,
        });
        Functions\when('update_option')->justReturn(true);

        if (!defined('DATAFLAIR_DB_VERSION')) {
            define('DATAFLAIR_DB_VERSION', '1.12');
        }

        $handler = new RunTestHandler(new TestsRunner());
        $result  = $handler->handle(['slug' => 'db_schema']);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('slug', $result['data']);
        $this->assertArrayHasKey('status', $result['data']);
        $this->assertArrayHasKey('message', $result['data']);
        $this->assertArrayHasKey('duration_ms', $result['data']);
        $this->assertArrayHasKey('last_run_iso', $result['data']);
        $this->assertSame('db_schema', $result['data']['slug']);
        $this->assertContains($result['data']['status'], ['pass', 'fail', 'warn']);
    }

    public function test_persists_result_via_update_option(): void
    {
        $persisted = false;
        Functions\when('get_option')->alias(static fn(string $key, $default = false) => match ($key) {
            'dataflair_db_version' => '1.12',
            TestsRunner::OPTION_KEY => [],
            default                 => $default,
        });
        Functions\when('update_option')->alias(static function () use (&$persisted) {
            $persisted = true;
            return true;
        });

        if (!defined('DATAFLAIR_DB_VERSION')) {
            define('DATAFLAIR_DB_VERSION', '1.12');
        }

        (new RunTestHandler(new TestsRunner()))->handle(['slug' => 'db_schema']);

        $this->assertTrue($persisted, 'update_option should have been called to persist the result');
    }
}
