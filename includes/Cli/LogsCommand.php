<?php
/**
 * Phase 1 — `wp dataflair logs` command.
 *
 * Tails DataFlair log lines from the configured logger. For ErrorLogLogger
 * (the default) that means parsing PHP's error_log destination and
 * returning only lines tagged `[DataFlair][...]`. For any other logger,
 * the command delegates to a filter `dataflair_logs_tail` so downstream
 * implementations (SentryLogger, file-based, etc.) can provide their own
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
            if (!$logger instanceof ErrorLogLogger) {
                $this->warn(sprintf(
                    'Active logger is %s; no tail provider registered. Hook `dataflair_logs_tail` to return an array of log lines.',
                    get_class($logger)
                ));
                $lines = [];
            } else {
                $lines = $this->tailErrorLog($since_ts, $min_lvl, $limit);
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

        // Stream-read the last ~512 KB to avoid loading massive logs into memory.
        $size = filesize($path);
        $read_bytes = min($size ?: 0, 512 * 1024);
        $fp = fopen($path, 'rb');
        if (!$fp) {
            return [];
        }
        if ($read_bytes > 0) {
            fseek($fp, -$read_bytes, SEEK_END);
        }
        $buf = stream_get_contents($fp);
        fclose($fp);
        if (!is_string($buf)) {
            return [];
        }

        $matches = [];
        foreach (explode("\n", $buf) as $raw) {
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
