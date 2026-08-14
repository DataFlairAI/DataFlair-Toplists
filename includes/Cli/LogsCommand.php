<?php
/**
 * Phase 1 — `wp dataflair logs` command.
 *
 * Tails DataFlair log lines from the configured logger. ErrorLogLogger and
 * FileLogger (the default since the persistent dataflair-sync.log feature)
 * are tailed natively — parsing PHP's error_log destination or the logger's
 * own file respectively, each returning only DataFlair-tagged lines. For
 * any other logger, the command delegates to a filter `dataflair_logs_tail`
 * so downstream implementations (SentryLogger, etc.) can provide their own
 * tail.
 *
 *   wp dataflair logs                    # last hour, all levels
 *   wp dataflair logs --since=15m        # last 15 minutes
 *   wp dataflair logs --level=warning    # warning and above
 *   wp dataflair logs --limit=50
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Cli;

use DataFlair\Toplists\Logging\ErrorLogLogger;
use DataFlair\Toplists\Logging\FileLogger;
use DataFlair\Toplists\Logging\LoggerFactory;

final class LogsCommand
{
    private const LEVELS = [
        'debug'     => 0,
        'info'      => 1,
        'notice'    => 2,
        'warning'   => 3,
        'error'     => 4,
        'critical'  => 5,
        'alert'     => 6,
        'emergency' => 7,
    ];

    /**
     * @param array<int, string>                 $args
     * @param array{since?: string, level?: string, limit?: string} $assoc
     */
    public function __invoke(array $args, array $assoc): void
    {
        $since_ts = $this->parseSince((string) ($assoc['since'] ?? '1h'));
        $min_lvl  = self::LEVELS[strtolower((string) ($assoc['level'] ?? 'debug'))] ?? self::LEVELS['debug'];
        $limit    = max(1, min(1000, (int) ($assoc['limit'] ?? 200)));

        $logger = LoggerFactory::get();

        // Give non-default loggers a chance to supply their own tail.
        $lines = function_exists('apply_filters')
            ? apply_filters('dataflair_logs_tail', null, $since_ts, $min_lvl, $limit, $logger)
            : null;

        if (!is_array($lines)) {
            if ($logger instanceof ErrorLogLogger) {
                $lines = $this->tailErrorLog($since_ts, $min_lvl, $limit);
            } elseif ($logger instanceof FileLogger) {
                $lines = $this->tailFileLogger($logger, $since_ts, $min_lvl, $limit);
            } else {
                $this->warn(sprintf(
                    'Active logger is %s; no tail provider registered. Hook `dataflair_logs_tail` to return an array of log lines.',
                    get_class($logger)
                ));
                $lines = [];
            }
        }

        if ($lines === []) {
            $this->log('(no matching log lines)');
            return;
        }

        foreach ($lines as $line) {
            $this->log((string) $line);
        }
    }

    /**
     * @return array<int, string>
     */
    private function tailErrorLog(int $since_ts, int $min_level, int $limit): array
    {
        $path = (string) ini_get('error_log');
        if ($path === '' || !is_readable($path)) {
            $this->warn('error_log destination is empty or not readable; check php.ini `error_log`.');
            return [];
        }

        $read = $this->readTailBytes($path);
        if ($read['buf'] === null) {
            return [];
        }

        $matches = [];
        foreach (explode("\n", $read['buf']) as $raw) {
            if (strpos($raw, '[DataFlair][') === false) {
                continue;
            }
            $ts = $this->extractTimestamp($raw);
            if ($ts !== null && $ts < $since_ts) {
                continue;
            }
            $lvl = $this->extractLevel($raw);
            if ($lvl !== null && $lvl < $min_level) {
                continue;
            }
            $matches[] = $raw;
        }

        if (count($matches) > $limit) {
            $matches = array_slice($matches, -$limit);
        }
        return $matches;
    }

    /**
     * @return array<int, string>
     */
    private function tailFileLogger(FileLogger $logger, int $since_ts, int $min_level, int $limit): array
    {
        $path = $logger->path();
        if ($path === '' || !is_readable($path)) {
            $this->warn('FileLogger path is empty or not readable: ' . $path);
            return [];
        }

        $read = $this->readTailBytes($path);
        if ($read['buf'] === null) {
            return [];
        }
        $buf = $read['buf'];

        // FileLogger keeps a single rotated generation (path . '.1'). If the
        // active file's entire content already fit inside the read window,
        // older lines may have rolled into that generation — pull it in too
        // so a --since window spanning a rotation doesn't silently drop them.
        $rotated = $path . '.1';
        if (!$read['truncated'] && is_readable($rotated)) {
            $prev = $this->readTailBytes($rotated);
            if ($prev['buf'] !== null && $prev['buf'] !== '') {
                $buf = rtrim($prev['buf'], "\n") . "\n" . $buf;
            }
        }

        $matches = [];
        foreach (explode("\n", $buf) as $raw) {
            if ($raw === '') {
                continue;
            }
            $ts = $this->extractFileLoggerTimestamp($raw);
            if ($ts !== null && $ts < $since_ts) {
                continue;
            }
            $lvl = $this->extractFileLoggerLevel($raw);
            if ($lvl !== null && $lvl < $min_level) {
                continue;
            }
            $matches[] = $raw;
        }

        if (count($matches) > $limit) {
            $matches = array_slice($matches, -$limit);
        }
        return $matches;
    }

    /**
     * Stream-read the last ~$maxBytes of a file. When the read doesn't start
     * at byte 0 (the file is bigger than $maxBytes), the leading fragment is
     * almost certainly a partial line — drop everything up to and including
     * the first newline so callers never see a truncated, unparseable line.
     *
     * @return array{buf: ?string, truncated: bool}
     */
    private function readTailBytes(string $path, int $maxBytes = 512 * 1024): array
    {
        $size = filesize($path);
        if ($size === false) {
            return ['buf' => null, 'truncated' => false];
        }

        $read_bytes = min($size, $maxBytes);
        $fp = fopen($path, 'rb');
        if (!$fp) {
            return ['buf' => null, 'truncated' => false];
        }

        $truncated = $read_bytes > 0 && $size > $read_bytes;
        if ($read_bytes > 0) {
            fseek($fp, -$read_bytes, SEEK_END);
        }
        $buf = stream_get_contents($fp);
        fclose($fp);
        if (!is_string($buf)) {
            return ['buf' => null, 'truncated' => false];
        }

        if ($truncated) {
            $nl = strpos($buf, "\n");
            $buf = $nl !== false ? substr($buf, $nl + 1) : '';
        }

        return ['buf' => $buf, 'truncated' => $truncated];
    }

    private function extractFileLoggerTimestamp(string $line): ?int
    {
        // FileLogger format: [YYYY-MM-DD HH:MM:SS UTC][LEVEL] message
        if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) UTC\]/', $line, $m)) {
            $ts = strtotime($m[1] . ' UTC');
            return is_int($ts) ? $ts : null;
        }
        return null;
    }

    private function extractFileLoggerLevel(string $line): ?int
    {
        if (preg_match('/^\[[^\]]+\]\[([A-Z]+)\]/', $line, $m)) {
            $lvl = strtolower($m[1]);
            return self::LEVELS[$lvl] ?? null;
        }
        return null;
    }

    private function extractTimestamp(string $line): ?int
    {
        // PHP error_log default format: [DD-Mon-YYYY HH:MM:SS UTC] ...
        if (preg_match('/^\[(\d{2}-\w{3}-\d{4} \d{2}:\d{2}:\d{2}(?:\s+[A-Za-z\/_+-]+)?)\]/', $line, $m)) {
            $ts = strtotime($m[1]);
            return is_int($ts) ? $ts : null;
        }
        return null;
    }

    private function extractLevel(string $line): ?int
    {
        if (preg_match('/\[DataFlair\]\[([A-Z]+)\]/', $line, $m)) {
            $lvl = strtolower($m[1]);
            return self::LEVELS[$lvl] ?? null;
        }
        return null;
    }

    private function parseSince(string $since): int
    {
        $since = trim(strtolower($since));
        if ($since === '' || $since === '0') {
            return 0;
        }
        if (preg_match('/^(\d+)([smhd])$/', $since, $m)) {
            $n = (int) $m[1];
            switch ($m[2]) {
                case 's': return time() - $n;
                case 'm': return time() - $n * 60;
                case 'h': return time() - $n * 3600;
                case 'd': return time() - $n * 86400;
            }
        }
        // Fallback: try strtotime for absolute dates.
        $ts = strtotime($since);
        return is_int($ts) ? $ts : (time() - 3600);
    }

    private function log(string $line): void
    {
        if (class_exists('\\WP_CLI')) {
            \WP_CLI::log($line);
            return;
        }
        // Use echo (not fwrite to STDOUT) so PHP output buffers capture
        // it — makes the command unit-testable under ob_start().
        echo $line . "\n";
    }

    private function warn(string $line): void
    {
        if (class_exists('\\WP_CLI')) {
            \WP_CLI::warning($line);
            return;
        }
        // Mirror warnings to stderr so test captures of stdout stay clean
        // (tests that want to assert on warnings can redirect stderr).
        fwrite(STDERR, 'Warning: ' . $line . "\n");
    }
}
