<?php
/**
 * Phase 1 — LogsCommand contract pin.
 *
 * Pins:
 *  - `--since` parses 15m, 1h, 3d, etc. (against the default FileLogger tail)
 *  - `--level` filters lines below the threshold (against the default FileLogger tail)
 *  - lines outside the DataFlair tag are ignored when tailing a shared
 *    ErrorLogLogger destination (e.g. wp-content/debug.log)
 *  - the `dataflair_logs_tail` filter can supply a custom tail for non-default loggers
 *  - limit caps output (against the default FileLogger tail)
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Logging;

use Brain\Monkey;
use Brain\Monkey\Filters;
use DataFlair\Toplists\Cli\LogsCommand;
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
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Cli/LogsCommand.php';

final class LogsCommandTest extends TestCase
{
    private ?string $origErrorLog = null;
    private string $tmp = '';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        LoggerFactory::reset();
        $this->tmp = tempnam(sys_get_temp_dir(), 'dflogs_test');
        $prev = ini_set('error_log', $this->tmp);
        $this->origErrorLog = $prev === false ? null : $prev;
    }

    protected function tearDown(): void
    {
        if ($this->origErrorLog !== null) {
            ini_set('error_log', $this->origErrorLog);
        }
        if (is_file($this->tmp)) {
            @unlink($this->tmp);
        }
        if (is_file($this->tmp . '.1')) {
            @unlink($this->tmp . '.1');
        }
        LoggerFactory::reset();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_tail_filters_non_dataflair_lines(): void
    {
        // Exercises the ErrorLogLogger tail path specifically: a shared
        // destination (e.g. wp-content/debug.log) can carry lines from
        // other plugins/PHP itself, which must be filtered out. The
        // default FileLogger writes to its own dedicated file and never
        // sees foreign lines, so this scenario doesn't apply to it.
        Filters\expectApplied('dataflair_logger_level')
            ->andReturnUsing(static fn($d) => $d);
        Filters\expectApplied('dataflair_logger')
            ->andReturn(new ErrorLogLogger());
        Filters\expectApplied('dataflair_logs_tail')
            ->andReturn(null);

        $now = date('d-M-Y H:i:s') . ' UTC';
        file_put_contents($this->tmp, implode("\n", [
            "[$now] PHP Notice: something unrelated in /var/www/html/some-other-plugin.php on line 3",
            "[$now] [DataFlair][NOTICE] sync.started",
            "[$now] [DataFlair][WARNING] http_call upstream latency 4s",
            "[$now] PHP Warning: something else in /var/www/html/whatever.php on line 7",
        ]) . "\n");

        $out = $this->captureOutput(static function () {
            (new LogsCommand())([], ['since' => '1h']);
        });

        $this->assertStringContainsString('[DataFlair][NOTICE] sync.started', $out);
        $this->assertStringContainsString('[DataFlair][WARNING] http_call', $out);
        $this->assertStringNotContainsString('some-other-plugin.php', $out);
        $this->assertStringNotContainsString('Warning: something else', $out);
    }

    public function test_level_filter_drops_lines_below_threshold(): void
    {
        Filters\expectApplied('dataflair_logger_level')->andReturnUsing(static fn($d) => $d);
        Filters\expectApplied('dataflair_logger')->andReturnUsing(static fn($d) => $d);
        Filters\expectApplied('dataflair_sync_log_path')->andReturn($this->tmp);
        Filters\expectApplied('dataflair_logs_tail')->andReturn(null);

        // FileLogger's own line shape: [YYYY-MM-DD HH:MM:SS UTC][LEVEL] message
        $now = gmdate('Y-m-d H:i:s') . ' UTC';
        file_put_contents($this->tmp, implode("\n", [
            "[$now][DEBUG] noise",
            "[$now][INFO] ok",
            "[$now][WARNING] bad",
            "[$now][ERROR] real",
        ]) . "\n");

        $out = $this->captureOutput(static function () {
            (new LogsCommand())([], ['since' => '1h', 'level' => 'warning']);
        });

        $this->assertStringNotContainsString('DEBUG', $out);
        $this->assertStringNotContainsString('INFO', $out);
        $this->assertStringContainsString('[WARNING] bad', $out);
        $this->assertStringContainsString('[ERROR] real', $out);
    }

    public function test_since_filter_drops_older_lines(): void
    {
        Filters\expectApplied('dataflair_logger_level')->andReturnUsing(static fn($d) => $d);
        Filters\expectApplied('dataflair_logger')->andReturnUsing(static fn($d) => $d);
        Filters\expectApplied('dataflair_sync_log_path')->andReturn($this->tmp);
        Filters\expectApplied('dataflair_logs_tail')->andReturn(null);

        $now = gmdate('Y-m-d H:i:s') . ' UTC';
        $old = gmdate('Y-m-d H:i:s', time() - 7200) . ' UTC';

        file_put_contents($this->tmp, implode("\n", [
            "[$old][NOTICE] old-event",
            "[$now][NOTICE] fresh-event",
        ]) . "\n");

        $out = $this->captureOutput(static function () {
            (new LogsCommand())([], ['since' => '1h']);
        });

        $this->assertStringNotContainsString('old-event', $out);
        $this->assertStringContainsString('fresh-event', $out);
    }

    public function test_filter_supplied_tail_takes_precedence(): void
    {
        Filters\expectApplied('dataflair_logger_level')->andReturnUsing(static fn($d) => $d);
        // Non-default logger: NullLogger.
        Filters\expectApplied('dataflair_logger')->andReturn(new NullLogger());
        Filters\expectApplied('dataflair_logs_tail')
            ->once()
            ->andReturn(['[custom-tail] hello world']);

        $out = $this->captureOutput(static function () {
            (new LogsCommand())([], ['since' => '1h']);
        });

        $this->assertStringContainsString('[custom-tail] hello world', $out);
    }

    public function test_limit_caps_output_to_most_recent_lines(): void
    {
        Filters\expectApplied('dataflair_logger_level')->andReturnUsing(static fn($d) => $d);
        Filters\expectApplied('dataflair_logger')->andReturnUsing(static fn($d) => $d);
        Filters\expectApplied('dataflair_sync_log_path')->andReturn($this->tmp);
        Filters\expectApplied('dataflair_logs_tail')->andReturn(null);

        $now = gmdate('Y-m-d H:i:s') . ' UTC';
        $lines = [];
        for ($i = 1; $i <= 10; $i++) {
            $lines[] = "[$now][NOTICE] event-$i";
        }
        file_put_contents($this->tmp, implode("\n", $lines) . "\n");

        $out = $this->captureOutput(static function () {
            (new LogsCommand())([], ['since' => '1h', 'limit' => '3']);
        });

        // Limit=3 should keep only the last three entries (events 8, 9, 10).
        // Use regex with a word-boundary so "event-1" doesn't match "event-10".
        $this->assertDoesNotMatchRegularExpression('/event-1\b/', $out);
        $this->assertDoesNotMatchRegularExpression('/event-2\b/', $out);
        $this->assertDoesNotMatchRegularExpression('/event-7\b/', $out);
        $this->assertMatchesRegularExpression('/event-8\b/', $out);
        $this->assertMatchesRegularExpression('/event-9\b/', $out);
        $this->assertMatchesRegularExpression('/event-10\b/', $out);
    }

    public function test_drops_partial_first_line_after_mid_file_seek(): void
    {
        Filters\expectApplied('dataflair_logger_level')->andReturnUsing(static fn($d) => $d);
        Filters\expectApplied('dataflair_logger')->andReturnUsing(static fn($d) => $d);
        Filters\expectApplied('dataflair_sync_log_path')->andReturn($this->tmp);
        Filters\expectApplied('dataflair_logs_tail')->andReturn(null);

        // Comfortably exceed the 512 KB tail-read window so the seek lands
        // mid-line rather than at byte 0 — a garbled leading fragment would
        // be missing its "[" prefix and must not appear in the output.
        $now = gmdate('Y-m-d H:i:s') . ' UTC';
        $lines = [];
        $size = 0;
        for ($i = 0; $size < 600 * 1024; $i++) {
            $line = "[$now][NOTICE] event-$i " . str_repeat('x', 80);
            $lines[] = $line;
            $size += strlen($line) + 1;
        }
        file_put_contents($this->tmp, implode("\n", $lines) . "\n");

        $out = $this->captureOutput(static function () {
            (new LogsCommand())([], ['since' => '1h', 'limit' => '5000']);
        });

        foreach (explode("\n", trim($out)) as $line) {
            if ($line === '') {
                continue;
            }
            $this->assertStringStartsWith('[', $line, "A truncated fragment leaked into the output: $line");
        }
    }

    public function test_reads_rotated_generation_when_active_file_alone_fits_the_window(): void
    {
        Filters\expectApplied('dataflair_logger_level')->andReturnUsing(static fn($d) => $d);
        Filters\expectApplied('dataflair_logger')->andReturnUsing(static fn($d) => $d);
        Filters\expectApplied('dataflair_sync_log_path')->andReturn($this->tmp);
        Filters\expectApplied('dataflair_logs_tail')->andReturn(null);

        $now = gmdate('Y-m-d H:i:s') . ' UTC';
        file_put_contents($this->tmp . '.1', "[$now][NOTICE] rotated-event\n");
        file_put_contents($this->tmp, "[$now][NOTICE] active-event\n");

        $out = $this->captureOutput(static function () {
            (new LogsCommand())([], ['since' => '1h']);
        });

        $this->assertStringContainsString('rotated-event', $out);
        $this->assertStringContainsString('active-event', $out);
    }

    private function captureOutput(callable $fn): string
    {
        ob_start();
        $fn();
        return (string) ob_get_clean();
    }
}
