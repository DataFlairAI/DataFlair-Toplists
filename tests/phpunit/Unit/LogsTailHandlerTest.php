<?php
/**
 * Phase 9.6 (admin UX redesign) — pins LogsTailHandler contract.
 *
 * Verifies the dispatch on the active logger (LoggerFactory::get()), via
 * ActiveLogSource (see ActiveLogSourceTest.php for that class's own
 * dispatch/precedence coverage — these tests focus on what LogsTailHandler
 * itself does with a resolved source: chunked reads, marker filtering,
 * multi-line entry grouping, and line parsing):
 *  - ErrorLogLogger: WP_DEBUG_LOG guard, missing file path, and DataFlair
 *    line filtering + parsing (ts / level / message extraction) against a
 *    shared destination (e.g. wp-content/debug.log).
 *  - FileLogger (the default since the persistent dataflair-sync.log
 *    feature): its own dedicated file (plus a rotated backup, if present)
 *    is read directly, with no `[DataFlair]` marker to filter on and its
 *    own line format to parse.
 *  - Any other logger: graceful empty state, no built-in tail support.
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
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Support/ActiveLogSource.php';
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

        // FileLogger's own line shape: [YYYY-MM-DD HH:MM:SS UTC][LEVEL] message
        // — no [DataFlair] marker, since the whole file is already dedicated.
        $lines = [
            '[2026-04-25 14:05:20 UTC][DEBUG] verbose trace',
            '[2026-04-25 14:05:22 UTC][INFO] Sync complete — 42 brands',
            '[2026-04-25 14:05:23 UTC][ERROR] DB write failed',
        ];
        file_put_contents($tmpDir . '/dataflair-sync.log', implode("\n", $lines) . "\n");

        try {
            $result = (new LogsTailHandler())->handle([]);
        } finally {
            unlink($tmpDir . '/dataflair-sync.log');
            rmdir($tmpDir);
        }

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

    /**
     * Regression test: FileLogger rotates its live file to "<path>.1" at
     * MAX_BYTES and starts fresh. Before this fix, entries written just
     * before a rotation were silently unreachable — the tail only ever
     * read the live file.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_tails_across_a_rotated_file_logger_backup(): void
    {
        $tmpDir = sys_get_temp_dir() . '/dataflair-test-' . uniqid();
        mkdir($tmpDir);
        define('WP_CONTENT_DIR', $tmpDir);

        $live    = $tmpDir . '/dataflair-sync.log';
        $rotated = $tmpDir . '/dataflair-sync.log.1';
        file_put_contents($rotated, "[2026-04-25 14:00:00 UTC][WARNING] older, pre-rotation entry\n");
        file_put_contents($live, "[2026-04-25 14:05:23 UTC][ERROR] fresh, post-rotation entry\n");

        try {
            $result = (new LogsTailHandler())->handle([]);
        } finally {
            unlink($live);
            unlink($rotated);
            rmdir($tmpDir);
        }

        $this->assertTrue($result['success']);
        $entries = $result['data']['entries'];

        $this->assertCount(2, $entries);
        // Newest-first: the live file's entry, then the rotated backup's.
        $this->assertStringContainsString('fresh, post-rotation', $entries[0]['message']);
        $this->assertStringContainsString('older, pre-rotation', $entries[1]['message']);
    }

    /**
     * Regression test: FileLogger writes $message verbatim, so a message
     * containing an embedded newline (e.g. a raw HTTP error body snippet)
     * produces multiple physical lines in dataflair-sync.log. Before this
     * fix, each continuation line rendered as its own spurious, info-level,
     * timestamp-less entry instead of being attached to its parent.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_groups_multi_line_file_logger_messages_into_one_entry(): void
    {
        $tmpDir = sys_get_temp_dir() . '/dataflair-test-' . uniqid();
        mkdir($tmpDir);
        define('WP_CONTENT_DIR', $tmpDir);

        $body = "[2026-04-25 14:05:23 UTC][ERROR] API error: <html>\n<body>500</body>\n</html>\n"
            . "[2026-04-25 14:05:24 UTC][INFO] retrying\n";
        file_put_contents($tmpDir . '/dataflair-sync.log', $body);

        try {
            $result = (new LogsTailHandler())->handle([]);
        } finally {
            unlink($tmpDir . '/dataflair-sync.log');
            rmdir($tmpDir);
        }

        $entries = $result['data']['entries'];

        // 2 logical entries, not 4 physical lines.
        $this->assertCount(2, $entries);
        $this->assertSame(2, $result['data']['total']);

        // Newest-first: the plain "retrying" entry, then the multi-line error.
        $this->assertSame('info', $entries[0]['level']);
        $this->assertSame('retrying', $entries[0]['message']);

        $this->assertSame('error', $entries[1]['level']);
        $this->assertStringContainsString("API error: <html>\n<body>500</body>\n</html>", $entries[1]['message']);
    }

    /**
     * Regression test for test-coverage: parseFileLoggerLine()'s fallback
     * branch (a line that never matches the "[TS UTC][LEVEL]" entry
     * format) previously had zero coverage, so a bad edit there could
     * silently corrupt every non-matching line's level/timestamp mapping
     * with nothing catching it. Triggered here via an orphaned leading
     * line groupFileLoggerLines() surfaces as its own entry (its "parent"
     * line, if any, fell outside the tail window).
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_parses_malformed_leading_line_via_fallback(): void
    {
        $tmpDir = sys_get_temp_dir() . '/dataflair-test-' . uniqid();
        mkdir($tmpDir);
        define('WP_CONTENT_DIR', $tmpDir);

        $body = "garbage from a truncated write\n"
            . "[2026-04-25 14:05:23 UTC][INFO] a real entry\n";
        file_put_contents($tmpDir . '/dataflair-sync.log', $body);

        try {
            $result = (new LogsTailHandler())->handle([]);
        } finally {
            unlink($tmpDir . '/dataflair-sync.log');
            rmdir($tmpDir);
        }

        $entries = $result['data']['entries'];
        $this->assertCount(2, $entries);

        // Newest-first: the well-formed entry, then the fallback-parsed one.
        $this->assertSame('info', $entries[0]['level']);
        $this->assertSame('a real entry', $entries[0]['message']);

        $this->assertSame('info', $entries[1]['level']); // fallback default
        $this->assertSame('', $entries[1]['ts']);          // fallback default
        $this->assertSame('garbage from a truncated write', $entries[1]['message']);
    }

    public function test_returns_notice_for_unsupported_logger(): void
    {
        Filters\expectApplied('dataflair_logger')->andReturn(new NullLogger());

        $result = (new LogsTailHandler())->handle([]);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['data']['entries']);
        $this->assertStringContainsString('NullLogger', $result['data']['notice']);
        $this->assertStringContainsString('disabled', $result['data']['notice']);
    }
}
