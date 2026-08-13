<?php
/**
 * Phase 9.6 (admin UX redesign) — Tail the active DataFlair log.
 *
 * The active logger is resolved via LoggerFactory and dispatched by type,
 * mirroring `wp dataflair logs` (includes/Cli/LogsCommand.php):
 *   - ErrorLogLogger (shared destination, e.g. wp-content/debug.log):
 *     read WP_DEBUG_LOG and keep only lines carrying the `[DataFlair]`
 *     marker it writes via PHP's error_log().
 *   - FileLogger (default since the persistent dataflair-sync.log
 *     feature): read the logger's own dedicated file directly — every
 *     line already belongs to DataFlair, and the line format carries no
 *     `[DataFlair]` marker to filter on.
 *   - Any other logger: no built-in tail support.
 *
 * Output: { entries: [ { line, level, ts, message }, … ], total, truncated }
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Ajax;

use DataFlair\Toplists\Admin\AjaxHandlerInterface;
use DataFlair\Toplists\Logging\ErrorLogLogger;
use DataFlair\Toplists\Logging\FileLogger;
use DataFlair\Toplists\Logging\LoggerFactory;

final class LogsTailHandler implements AjaxHandlerInterface
{
    private const MAX_LINES = 200;
    private const DF_MARKER = '[DataFlair]';

    public function handle(array $request): array
    {
        $logger = LoggerFactory::get();

        if ($logger instanceof ErrorLogLogger) {
            return $this->tailErrorLog();
        }

        if ($logger instanceof FileLogger) {
            return $this->tailFileLogger($logger);
        }

        return $this->emptyState(sprintf(
            'Log viewer does not support the active logger (%s).',
            get_class($logger)
        ));
    }

    private function tailErrorLog(): array
    {
        if (!defined('WP_DEBUG_LOG') || !WP_DEBUG_LOG) {
            return $this->emptyState('Enable WP_DEBUG_LOG in wp-config.php to capture log output.');
        }

        $log_path = $this->resolveLogPath();
        if ($log_path === null || !is_readable($log_path)) {
            return $this->emptyState('debug.log not found or not readable.');
        }

        $lines = array_values(array_filter(
            $this->tailLastChunk($log_path),
            static fn(string $l) => str_contains($l, self::DF_MARKER)
        ));

        return $this->buildResponse($lines, [$this, 'parseLine']);
    }

    private function tailFileLogger(FileLogger $logger): array
    {
        $path = $logger->path();
        if ($path === '' || !is_readable($path)) {
            return $this->emptyState('dataflair-sync.log not found or not readable.');
        }

        $lines = array_values(array_filter(
            $this->tailLastChunk($path),
            static fn(string $l) => $l !== ''
        ));

        return $this->buildResponse($lines, [$this, 'parseFileLoggerLine']);
    }

    /**
     * @param array<int,string> $lines
     */
    private function buildResponse(array $lines, callable $parser): array
    {
        $total     = count($lines);
        $truncated = $total > self::MAX_LINES;
        $slice     = array_slice($lines, -self::MAX_LINES);
        $slice     = array_reverse($slice);   // newest-first

        return ['success' => true, 'data' => [
            'entries'   => array_map($parser, $slice),
            'total'     => $total,
            'truncated' => $truncated,
            'notice'    => '',
        ]];
    }

    private function emptyState(string $notice): array
    {
        return ['success' => true, 'data' => [
            'entries'   => [],
            'total'     => 0,
            'truncated' => false,
            'notice'    => $notice,
        ]];
    }

    /**
     * Read only the last 2MB of a file, dropping any leading partial line.
     * Avoids loading a large log file into memory.
     *
     * @return array<int,string>
     */
    private function tailLastChunk(string $path): array
    {
        $chunk_size = 2 * 1024 * 1024; // 2MB
        $size       = filesize($path);
        if ($size === false || $size === 0) {
            return [];
        }

        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return [];
        }

        $offset = max(0, $size - $chunk_size);
        fseek($fh, $offset);
        $chunk = fread($fh, $chunk_size);
        fclose($fh);

        if ($chunk === false || $chunk === '') {
            return [];
        }

        // If we didn't start from the beginning, drop the first partial line.
        if ($offset > 0) {
            $nl = strpos($chunk, "\n");
            if ($nl !== false) {
                $chunk = substr($chunk, $nl + 1);
            }
        }

        return explode("\n", rtrim($chunk));
    }

    /** Return the debug.log path, respecting string WP_DEBUG_LOG values. */
    private function resolveLogPath(): ?string
    {
        if (is_string(WP_DEBUG_LOG) && WP_DEBUG_LOG !== '') {
            return WP_DEBUG_LOG;
        }
        return defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/debug.log' : null;
    }

    /**
     * Parse a raw log line into a structured entry.
     *
     * Typical format written by ErrorLogLogger via PHP's error_log():
     *   [25-Apr-2026 14:05:22 UTC] [DataFlair][INFO] Sync complete
     * or plain:
     *   [DataFlair][ERROR] Something went wrong
     */
    private function parseLine(string $line): array
    {
        $ts      = '';
        $level   = 'info';
        $message = $line;

        // Extract PHP error_log timestamp: [DD-Mon-YYYY HH:MM:SS UTC]
        if (preg_match('/^\[([^\]]+)\]\s*(.*)$/', $line, $m)) {
            // Only treat as timestamp if it looks like a date
            if (preg_match('/\d{2}-[A-Za-z]+-\d{4}/', $m[1])) {
                $ts      = $m[1];
                $message = $m[2];
            }
        }

        // Extract [DataFlair][LEVEL] prefix from the remaining message
        if (preg_match('/\[DataFlair\]\[(\w+)\]\s*(.*)/s', $message, $m)) {
            $level   = strtolower($m[1]);
            $message = $m[2];
        } elseif (preg_match('/\[DataFlair\]\s*(.*)/s', $message, $m)) {
            $message = $m[1];
        }

        return [
            'line'    => $line,
            'ts'      => $ts,
            'level'   => $level,
            'message' => trim($message),
        ];
    }

    /**
     * Parse a raw FileLogger line into a structured entry.
     *
     * Format written by FileLogger:
     *   [2026-04-25 14:05:22 UTC][INFO] Sync complete
     */
    private function parseFileLoggerLine(string $line): array
    {
        if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) UTC\]\[(\w+)\]\s*(.*)$/s', $line, $m)) {
            return [
                'line'    => $line,
                'ts'      => $m[1] . ' UTC',
                'level'   => strtolower($m[2]),
                'message' => trim($m[3]),
            ];
        }

        return [
            'line'    => $line,
            'ts'      => '',
            'level'   => 'info',
            'message' => trim($line),
        ];
    }
}
