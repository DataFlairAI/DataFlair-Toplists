<?php
/**
 * Phase 1 — LogsCommand contract pin.
 *
 * Pins:
 *  - `--since` parses 15m, 1h, 3d, etc.
 *  - `--level` filters lines below the threshold
 *  - lines outside the DataFlair tag are ignored
 *  - the `dataflair_logs_tail` filter can supply a custom tail for non-default loggers
 *  - limit caps output
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Logging;

use Brain\Monkey;
use Brain\Monkey\Filters;
use DataFlair\Toplists\Cli\LogsCommand;
use DataFlair\Toplists\Logging\LoggerFactory;
use DataFlair\Toplists\Logging\NullLogger;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/NullLogger.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/ErrorLogLogger.php';
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
        LoggerFactory::reset();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_tail_filters_non_dataflair_lines(): void
    {
        Filters\expectApplied('dataflair_logger_level')
            ->andReturnUsing(static fn($d) => $d);
        Filters\expectApplied('dataflair_logger')
            ->andReturnUsing(static fn($d) => $d);
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
        Filters\expectApplied('dataflair_logs_tail')->andReturn(null);

        $now = date('d-M-Y H:i:s') . ' UTC';
        file_put_contents($this->tmp, implode("\n", [
            "[$now] [DataFlair][DEBUG] noise",
            "[$now] [DataFlair][INFO] ok",
            "[$now] [DataFlair][WARNING] bad",
            "[$now] [DataFlair][ERROR] real",
        ]) . "\n");

        $out = $this->captureOutput(static function () {
            (new LogsCommand())([], ['since' => '1h', 'level' => 'warning']);
        });

        $this->assertStringNotContainsString('DEBUG', $out);
        $this->assertStringNotContainsString('INFO', $out);
        $this->assertStringContainsString('[DataFlair][WARNING] bad', $out);
        $this->assertStringContainsString('[DataFlair][ERROR] real', $out);
    }

    public function test_since_filter_drops_older_lines(): void
    {
        Filters\expectApplied('dataflair_logger_level')->andReturnUsing(static fn($d) => $d);
        Filters\expectApplied('dataflair_logger')->andReturnUsing(static fn($d) => $d);
        Filters\expectApplied('dataflair_logs_tail')->andReturn(null);

        $now = date('d-M-Y H:i:s') . ' UTC';
        $old = date('d-M-Y H:i:s', time() - 7200) . ' UTC';

        file_put_contents($this->tmp, implode("\n", [
            "[$old] [DataFlair][NOTICE] old-event",
            "[$now] [DataFlair][NOTICE] fresh-event",
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
        Filters\expectApplied('dataflair_logs_tail')->andReturn(null);

        $now = date('d-M-Y H:i:s') . ' UTC';
        $lines = [];
        for ($i = 1; $i <= 10; $i++) {
            $lines[] = "[$now] [DataFlair][NOTICE] event-$i";
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

    private function captureOutput(callable $fn): string
    {
        ob_start();
        $fn();
        return (string) ob_get_clean();
    }
}
