<?php
/**
 * Phase 9.6 (admin UX redesign) — Tail the WP debug log, filtered to
 * DataFlair entries.
 *
 * Reads `wp-content/debug.log`, retains only lines that contain the
 * `[DataFlair]` prefix written by ErrorLogLogger, parses `[LEVEL]` for
 * severity colouring, and returns the last 200 entries newest-first.
 *
 * Guards:
 *   - WP_DEBUG_LOG not defined / false  → empty-state message
 *   - Log file missing or unreadable    → empty-state message
 *
 * Output: { entries: [ { line, level, ts, message }, … ], total, truncated }
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Ajax;

use DataFlair\Toplists\Admin\AjaxHandlerInterface;

final class LogsTailHandler implements AjaxHandlerInterface
{
    private const MAX_LINES   = 200;
    private const DF_MARKER   = '[DataFlair]';

    public function handle(array $request): array
    {
        if (!defined('WP_DEBUG_LOG') || !WP_DEBUG_LOG) {
            return ['success' => true, 'data' => [
                'entries'   => [],
                'total'     => 0,
                'truncated' => false,
                'notice'    => 'Enable WP_DEBUG_LOG in wp-config.php to capture log output.',
            ]];
        }

        $log_path = $this->resolveLogPath();
        if ($log_path === null || !is_readable($log_path)) {
            return ['success' => true, 'data' => [
                'entries'   => [],
                'total'     => 0,
                'truncated' => false,
                'notice'    => 'debug.log not found or not readable.',
            ]];
        }

        $df_lines  = $this->tailFilteredLines($log_path);
        $total     = count($df_lines);
        $truncated = $total > self::MAX_LINES;
        $slice     = array_slice($df_lines, -self::MAX_LINES);
        $slice     = array_reverse($slice);   // newest-first

        $entries = array_map([$this, 'parseLine'], $slice);

        return ['success' => true, 'data' => [
            'entries'   => $entries,
            'total'     => $total,
            'truncated' => $truncated,
            'notice'    => '',
        ]];
    }

    /**
     * Read only the last 2MB of the log file and return lines containing
     * the DataFlair marker. Avoids loading a large debug.log into memory.
     */
    private function tailFilteredLines(string $path): array
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

        $lines = explode("\n", rtrim($chunk));
        return array_values(array_filter($lines, static fn(string $l) => str_contains($l, self::DF_MARKER)));
    }

    /** Return the log file path, respecting string WP_DEBUG_LOG values. */
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
     * Typical format written by ErrorLogLogger:
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
}
