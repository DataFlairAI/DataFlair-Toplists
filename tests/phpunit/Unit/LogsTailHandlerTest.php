<?php
/**
 * Phase 9.6 (admin UX redesign) — pins LogsTailHandler contract.
 *
 * Verifies the dispatch on the active logger (LoggerFactory::get()):
 *  - ErrorLogLogger: WP_DEBUG_LOG guard, missing file path, and DataFlair
 *    line filtering + parsing (ts / level / message extraction) against a
 *    shared destination (e.g. wp-content/debug.log).
 *  - FileLogger (the default since the persistent dataflair-sync.log
 *    feature): its own dedicated file is read directly, with no
 *    `[DataFlair]` marker to filter on and its own line format to parse.
 *  - Any other logger: graceful empty state, no built-in tail support.
 *
 * Tests that require WP_DEBUG_LOG / WP_CONTENT_DIR to be defined run in
 * separate processes so each gets a pristine constant state.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Admin\Ajax;

use Brain\Monkey;
use Brain\Monkey\Filters;
use DataFlair\Toplists\Admin\Ajax\LogsTailHandler;
use DataFlair\Toplists\Logging\ErrorLogLogger;
use DataFlair\Toplists\Logging\LoggerFactory;
use DataFlair\Toplists\Logging\NullLogger;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/NullLogger.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/ErrorLogLogger.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/FileLogger.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/SentryLogger.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerFactory.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/AjaxHandlerInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Ajax/LogsTailHandler.php';

final class LogsTailHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        LoggerFactory::reset();
    }

    protected function tearDown(): void
    {
        LoggerFactory::reset();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_returns_notice_when_wp_debug_log_not_defined(): void
    {
        if (defined('WP_DEBUG_LOG')) {
            $this->markTestSkipped('WP_DEBUG_LOG already defined in this process.');
        }

        Filters\expectApplied('dataflair_logger')->andReturn(new ErrorLogLogger());

        $result = (new LogsTailHandler())->handle([]);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['data']['entries']);
        $this->assertNotEmpty($result['data']['notice']);
        $this->assertStringContainsStringIgnoringCase('WP_DEBUG_LOG', $result['data']['notice']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_returns_notice_when_wp_debug_log_false(): void
    {
        define('WP_DEBUG_LOG', false);
        define('WP_CONTENT_DIR', '/tmp');

        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerInterface.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/ErrorLogLogger.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/FileLogger.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerFactory.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/AjaxHandlerInterface.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Ajax/LogsTailHandler.php';

        Filters\expectApplied('dataflair_logger')->andReturn(new ErrorLogLogger());

        $result = (new LogsTailHandler())->handle([]);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['data']['entries']);
        $this->assertNotEmpty($result['data']['notice']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_returns_notice_when_log_file_not_readable(): void
    {
        define('WP_DEBUG_LOG', true);
        define('WP_CONTENT_DIR', '/tmp/dataflair-nonexistent-' . uniqid());

        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerInterface.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/ErrorLogLogger.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/FileLogger.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerFactory.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/AjaxHandlerInterface.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Ajax/LogsTailHandler.php';

        Filters\expectApplied('dataflair_logger')->andReturn(new ErrorLogLogger());

        $result = (new LogsTailHandler())->handle([]);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['data']['entries']);
        $this->assertStringContainsStringIgnoringCase('not found', $result['data']['notice']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_filters_and_parses_dataflair_lines(): void
    {
        // Exercises the ErrorLogLogger tail path specifically: a shared
        // destination (e.g. wp-content/debug.log) can carry lines from
        // other plugins/PHP itself, which must be filtered out via the
        // [DataFlair] marker. FileLogger writes to its own dedicated file
        // and never sees foreign lines, so this scenario doesn't apply to
        // it (see test_tails_and_parses_file_logger_lines_by_default).
        $tmpDir  = sys_get_temp_dir();
        $logFile = $tmpDir . '/dataflair-test-debug-' . uniqid() . '.log';

        $lines = [
            '[25-Apr-2026 14:05:22 UTC] [DataFlair][INFO] Sync complete — 42 brands',
            '[25-Apr-2026 14:05:23 UTC] [DataFlair][ERROR] DB write failed',
            '[25-Apr-2026 14:05:24 UTC] Some unrelated WordPress notice',
            '[DataFlair][WARN] No token configured',
        ];
        file_put_contents($logFile, implode(PHP_EOL, $lines) . PHP_EOL);

        define('WP_DEBUG_LOG', $logFile);
        define('WP_CONTENT_DIR', $tmpDir);

        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerInterface.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/ErrorLogLogger.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/FileLogger.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerFactory.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/AjaxHandlerInterface.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Ajax/LogsTailHandler.php';

        Filters\expectApplied('dataflair_logger')->andReturn(new ErrorLogLogger());

        $result = (new LogsTailHandler())->handle([]);

        unlink($logFile);

        $this->assertTrue($result['success']);
        $entries = $result['data']['entries'];

        // 3 DataFlair lines; 1 unrelated line excluded
        $this->assertCount(3, $entries);

        // Newest-first: last DataFlair line (WARN) comes first
        $this->assertSame('warn', $entries[0]['level']);
        $this->assertSame('error', $entries[1]['level']);
        $this->assertSame('info', $entries[2]['level']);

        // Messages extracted correctly
        $this->assertStringContainsString('Sync complete', $entries[2]['message']);
        $this->assertStringContainsString('DB write failed', $entries[1]['message']);
        $this->assertStringContainsString('No token', $entries[0]['message']);

        // Timestamp parsed for lines that have one
        $this->assertNotEmpty($entries[1]['ts']);
        $this->assertStringContainsString('Apr', $entries[1]['ts']);

        $this->assertFalse($result['data']['truncated']);
        $this->assertSame(3, $result['data']['total']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_returns_notice_when_file_logger_path_not_readable(): void
    {
        define('WP_CONTENT_DIR', '/tmp/dataflair-nonexistent-' . uniqid());

        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerInterface.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/ErrorLogLogger.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/FileLogger.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerFactory.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/AjaxHandlerInterface.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Ajax/LogsTailHandler.php';

        // No `dataflair_logger` override: proves the *default* logger
        // (FileLogger, per LoggerFactory) is the one being exercised.
        $result = (new LogsTailHandler())->handle([]);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['data']['entries']);
        $this->assertStringContainsStringIgnoringCase('not found', $result['data']['notice']);
    }

    /**
     * Regression test for the bug where LogsTailHandler hardcoded reading
     * wp-content/debug.log and filtering for the [DataFlair] marker that
     * only ErrorLogLogger writes. Since LoggerFactory's default logger
     * became FileLogger (persistent dataflair-sync.log), every fresh
     * install silently showed an empty log viewer. No `dataflair_logger`
     * filter override here — this exercises the real default end to end.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_tails_and_parses_file_logger_lines_by_default(): void
    {
        $tmpDir = sys_get_temp_dir() . '/dataflair-test-' . uniqid();
        mkdir($tmpDir);
        define('WP_CONTENT_DIR', $tmpDir);

        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerInterface.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/ErrorLogLogger.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/FileLogger.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerFactory.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/AjaxHandlerInterface.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Ajax/LogsTailHandler.php';

        // FileLogger's own line shape: [YYYY-MM-DD HH:MM:SS UTC][LEVEL] message
        // — no [DataFlair] marker, since the whole file is already dedicated.
        $lines = [
            '[2026-04-25 14:05:20 UTC][DEBUG] verbose trace',
            '[2026-04-25 14:05:22 UTC][INFO] Sync complete — 42 brands',
            '[2026-04-25 14:05:23 UTC][ERROR] DB write failed',
        ];
        file_put_contents($tmpDir . '/dataflair-sync.log', implode("\n", $lines) . "\n");

        $result = (new LogsTailHandler())->handle([]);

        $this->assertTrue($result['success']);
        $entries = $result['data']['entries'];

        $this->assertCount(3, $entries);
        $this->assertSame(3, $result['data']['total']);
        $this->assertFalse($result['data']['truncated']);
        $this->assertSame('', $result['data']['notice']);

        // Newest-first
        $this->assertSame('error', $entries[0]['level']);
        $this->assertSame('info', $entries[1]['level']);
        $this->assertSame('debug', $entries[2]['level']);

        $this->assertStringContainsString('DB write failed', $entries[0]['message']);
        $this->assertStringContainsString('Sync complete', $entries[1]['message']);

        $this->assertSame('2026-04-25 14:05:23 UTC', $entries[0]['ts']);
    }

    public function test_returns_notice_for_unsupported_logger(): void
    {
        Filters\expectApplied('dataflair_logger')->andReturn(new NullLogger());

        $result = (new LogsTailHandler())->handle([]);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['data']['entries']);
        $this->assertStringContainsString('NullLogger', $result['data']['notice']);
    }
}
