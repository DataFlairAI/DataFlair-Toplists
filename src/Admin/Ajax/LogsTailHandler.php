<?php
/**
 * Phase 9.6 (admin UX redesign) — Tail the active DataFlair log.
 *
 * Which file(s) to read, and whether [DataFlair]-marker filtering applies,
 * is resolved by ActiveLogSource (includes/Support/ActiveLogSource.php)
 * from the currently active logger (LoggerFactory::get()) — see that
 * class's docblock for the ErrorLogLogger/FileLogger/unsupported dispatch.
 *
 * Output: { entries: [ { line, level, ts, message }, … ], total, truncated }
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Ajax;

use DataFlair\Toplists\Admin\AjaxHandlerInterface;
use DataFlair\Toplists\Support\ActiveLogSource;

final class LogsTailHandler implements AjaxHandlerInterface
{
    private const MAX_LINES = 200;

    /** Start of a FileLogger entry: "[YYYY-MM-DD HH:MM:SS UTC][LEVEL] ...". */
    private const FILE_LOGGER_ENTRY_START = '/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} UTC\]\[\w+\]/';

    public function handle(array $request): array
    {
        $source = (new ActiveLogSource())->resolve();

        if ($source['error'] !== null) {
            return $this->emptyState($source['error']);
        }

        $rawLines = [];
        foreach ($source['paths'] as $path) {
            $rawLines = array_merge($rawLines, $this->tailLastChunk($path));
        }

        if ($source['filterMarker']) {
            $lines = array_values(array_filter(
                $rawLines,
                static fn(string $l) => str_contains($l, ActiveLogSource::DF_MARKER)
            ));
            return $this->buildResponse($lines, [$this, 'parseLine']);
        }

        $entries = $this->groupFileLoggerLines($rawLines);
        return $this->buildResponse($entries, [$this, 'parseFileLoggerLine']);
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

    /**
     * Regroup raw physical lines from FileLogger's own file into logical
     * entries: a line starting a new "[TS UTC][LEVEL]" entry begins a new
     * entry; any line that doesn't match is a continuation of the previous
     * entry's message (e.g. a logged error body with embedded newlines)
     * rather than a spurious entry of its own.
     *
     * @param array<int,string> $rawLines
     * @return array<int,string>
     */
    private function groupFileLoggerLines(array $rawLines): array
    {
        $entries = [];
        $current = null;

        foreach ($rawLines as $raw) {
            if ($raw === '') {
                continue;
            }
            if (preg_match(self::FILE_LOGGER_ENTRY_START, $raw)) {
                if ($current !== null) {
                    $entries[] = $current;
                }
                $current = $raw;
            } elseif ($current !== null) {
                $current .= "\n" . $raw;
            } else {
                // Orphaned continuation at the start of the tail window
                // (its parent line fell outside the read chunk) — surface
                // it rather than silently dropping it.
                $entries[] = $raw;
            }
        }
        if ($current !== null) {
            $entries[] = $current;
        }

        return $entries;
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
     * Parse a raw FileLogger entry (possibly spanning multiple physical
     * lines, see groupFileLoggerLines()) into a structured entry.
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
