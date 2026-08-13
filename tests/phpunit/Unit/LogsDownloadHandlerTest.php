<?php
/**
 * Phase 9.6 (admin UX redesign) — pins LogsDownloadHandler contract.
 *
 * Verifies the dispatch on the active logger (LoggerFactory::get()), via
 * ActiveLogSource (see ActiveLogSourceTest.php for that class's own
 * dispatch/precedence coverage — these tests focus on what
 * LogsDownloadHandler itself does with a resolved source: reading,
 * marker filtering, and streaming), mirroring LogsTailHandlerTest and
 * `wp dataflair logs`:
 *  - ErrorLogLogger: reads WP_DEBUG_LOG (or wp-content/debug.log) and
 *    keeps only lines carrying the `[DataFlair]` marker.
 *  - FileLogger (the default since the persistent dataflair-sync.log
 *    feature): reads the logger's own dedicated file (plus a rotated
 *    backup, if present) directly, unfiltered.
 *  - Any other logger: graceful error response, no built-in download
 *    support.
 *
 * Tests that require WP_DEBUG_LOG / WP_CONTENT_DIR to be defined run in
 * separate processes so each gets a pristine constant state. PHPUnit's
 * process-isolation template re-requires this whole file (including the
 * top-of-file requires below) before invoking the method body in the
 * child process, so those requires alone are sufficient — no per-method
 * repetition needed.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Admin\Ajax;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use DataFlair\Toplists\Admin\Ajax\LogsDownloadHandler;
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
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Support/ActiveLogSource.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/AjaxHandlerInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Ajax/LogsDownloadHandler.php';

final class LogsDownloadHandlerTest extends TestCase
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

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_returns_error_when_error_log_not_readable(): void
    {
        define('WP_DEBUG_LOG', '/tmp/dataflair-nonexistent-' . uniqid() . '.log');

        Filters\expectApplied('dataflair_logger')->andReturn(new ErrorLogLogger());

        $result = (new LogsDownloadHandler())->handle([]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsStringIgnoringCase('not found', $result['data']['message']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_streams_only_dataflair_marked_lines_from_error_log(): void
    {
        $tmpDir  = sys_get_temp_dir();
        $logFile = $tmpDir . '/dataflair-test-debug-' . uniqid() . '.log';

        file_put_contents($logFile, implode(PHP_EOL, [
            '[25-Apr-2026 14:05:22 UTC] [DataFlair][INFO] Sync complete',
            '[25-Apr-2026 14:05:24 UTC] Some unrelated WordPress notice',
            '[DataFlair][ERROR] DB write failed',
        ]) . PHP_EOL);

        define('WP_DEBUG_LOG', $logFile);
        define('WP_CONTENT_DIR', $tmpDir);

        Filters\expectApplied('dataflair_logger')->andReturn(new ErrorLogLogger());
        // wp_die() always terminates in production; stubbing it as a no-op
        // is what lets this test observe the streamed body. That's exactly
        // the scenario stream()'s post-wp_die() safety net guards against,
        // so the call is expected to throw once the body has already been
        // echoed, rather than falling through to a fabricated "success".
        Functions\when('wp_die')->justReturn(null);

        ob_start();
        try {
            (new LogsDownloadHandler())->handle([]);
            $this->fail('Expected stream() to throw once wp_die() failed to terminate.');
        } catch (\RuntimeException $e) {
            // expected
        }
        $output = ob_get_clean();

        unlink($logFile);

        $this->assertStringContainsString('Sync complete', $output);
        $this->assertStringContainsString('DB write failed', $output);
        $this->assertStringNotContainsString('unrelated WordPress notice', $output);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_returns_error_when_file_logger_path_not_readable(): void
    {
        define('WP_CONTENT_DIR', '/tmp/dataflair-nonexistent-' . uniqid());

        // No `dataflair_logger` override: proves the *default* logger
        // (FileLogger, per LoggerFactory) is the one being exercised.
        $result = (new LogsDownloadHandler())->handle([]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsStringIgnoringCase('not found', $result['data']['message']);
    }

    /**
     * Regression test for the bug where LogsDownloadHandler hardcoded
     * reading wp-content/debug.log and filtering for the [DataFlair]
     * marker that only ErrorLogLogger writes. Since LoggerFactory's
     * default logger became FileLogger (persistent dataflair-sync.log),
     * downloading the log on a fresh install silently produced an empty
     * file. No `dataflair_logger` filter override here — this exercises
     * the real default end to end.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_streams_file_logger_lines_by_default_unfiltered(): void
    {
        $tmpDir = sys_get_temp_dir() . '/dataflair-test-' . uniqid();
        mkdir($tmpDir);
        define('WP_CONTENT_DIR', $tmpDir);

        // FileLogger's own line shape carries no [DataFlair] marker — the
        // whole file is already dedicated, so nothing should be filtered.
        $logFile = $tmpDir . '/dataflair-sync.log';
        file_put_contents($logFile, implode("\n", [
            '[2026-04-25 14:05:22 UTC][INFO] Sync complete — 42 brands',
            '[2026-04-25 14:05:23 UTC][ERROR] DB write failed',
        ]) . "\n");

        Functions\when('wp_die')->justReturn(null);

        ob_start();
        try {
            (new LogsDownloadHandler())->handle([]);
            $this->fail('Expected stream() to throw once wp_die() failed to terminate.');
        } catch (\RuntimeException $e) {
            // expected
        } finally {
            $output = ob_get_clean();
            unlink($logFile);
            rmdir($tmpDir);
        }

        $this->assertStringContainsString('Sync complete', $output);
        $this->assertStringContainsString('DB write failed', $output);
    }

    /**
     * Regression test: FileLogger rotates its live file to "<path>.1" at
     * MAX_BYTES and starts fresh. Before this fix, entries written just
     * before a rotation were silently omitted from the downloaded file.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_streams_across_a_rotated_file_logger_backup(): void
    {
        $tmpDir = sys_get_temp_dir() . '/dataflair-test-' . uniqid();
        mkdir($tmpDir);
        define('WP_CONTENT_DIR', $tmpDir);

        $live    = $tmpDir . '/dataflair-sync.log';
        $rotated = $tmpDir . '/dataflair-sync.log.1';
        file_put_contents($rotated, "[2026-04-25 14:00:00 UTC][WARNING] older, pre-rotation entry\n");
        file_put_contents($live, "[2026-04-25 14:05:23 UTC][ERROR] fresh, post-rotation entry\n");

        Functions\when('wp_die')->justReturn(null);

        ob_start();
        try {
            (new LogsDownloadHandler())->handle([]);
        } catch (\RuntimeException $e) {
            // expected — see test_streams_file_logger_lines_by_default_unfiltered
        } finally {
            $output = ob_get_clean();
            unlink($live);
            unlink($rotated);
            rmdir($tmpDir);
        }

        $this->assertStringContainsString('older, pre-rotation entry', $output);
        $this->assertStringContainsString('fresh, post-rotation entry', $output);
        // Rotated (older) content precedes the live file's content.
        $this->assertLessThan(
            strpos($output, 'fresh, post-rotation entry'),
            strpos($output, 'older, pre-rotation entry')
        );
    }

    public function test_returns_error_for_unsupported_logger(): void
    {
        Filters\expectApplied('dataflair_logger')->andReturn(new NullLogger());

        $result = (new LogsDownloadHandler())->handle([]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('NullLogger', $result['data']['message']);
        $this->assertStringContainsString('disabled', $result['data']['message']);
    }
}
