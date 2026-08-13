<?php
/**
 * Phase 9.6 (admin UX redesign) — Stream the active DataFlair log as a
 * text/plain download.
 *
 * Which file(s) to read, and whether [DataFlair]-marker filtering applies,
 * is resolved by ActiveLogSource (includes/Support/ActiveLogSource.php)
 * from the currently active logger (LoggerFactory::get()) — see that
 * class's docblock for the ErrorLogLogger/FileLogger/unsupported dispatch.
 *
 * Uses the admin-ajax.php route (nopriv=false) with `Content-Disposition:
 * attachment` so the admin can save the log locally. The nonce check is
 * done by AjaxRouter before this handler fires. After streaming, calls
 * wp_die() to terminate — normal for download handlers.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Ajax;

use DataFlair\Toplists\Admin\AjaxHandlerInterface;
use DataFlair\Toplists\Support\ActiveLogSource;

final class LogsDownloadHandler implements AjaxHandlerInterface
{
    public function handle(array $request): array
    {
        $source = (new ActiveLogSource())->resolve();

        if ($source['error'] !== null) {
            return ['success' => false, 'data' => ['message' => $source['error']]];
        }

        // Common case: a single unfiltered file (FileLogger, no rotated
        // backup pending) — stream it straight from disk instead of
        // loading it into an array and rejoining it into one big string.
        if (count($source['paths']) === 1 && !$source['filterMarker']) {
            $this->streamFile($source['paths'][0]);
        }

        $lines = [];
        foreach ($source['paths'] as $path) {
            $lines = array_merge($lines, $this->readLinesOrEmpty($path));
        }

        if ($source['filterMarker']) {
            $lines = array_filter($lines, static fn(string $l) => str_contains($l, ActiveLogSource::DF_MARKER));
        }

        $this->stream($lines);
    }

    /**
     * Streams a file straight from disk as a text/plain download and
     * terminates via wp_die(). See stream() for the fallback-throw
     * rationale.
     */
    private function streamFile(string $path): never
    {
        $this->sendDownloadHeaders();
        readfile($path);
        wp_die();

        throw new \RuntimeException('wp_die() did not terminate the request.');
    }

    /**
     * Streams the given lines as a text/plain download and terminates via
     * wp_die(). wp_die() always terminates in a real WordPress request; the
     * throw below is a safety net for the (only ever filter-induced) case
     * where it doesn't, so a non-terminating wp_die() fails loudly instead
     * of silently falling through to a "success" response appended after
     * the already-streamed body.
     *
     * @param array<int,string> $lines
     */
    private function stream(array $lines): never
    {
        $this->sendDownloadHeaders();
        echo implode(PHP_EOL, $lines);
        wp_die();

        throw new \RuntimeException('wp_die() did not terminate the request.');
    }

    private function sendDownloadHeaders(): void
    {
        $filename = 'dataflair-debug-' . gmdate('Y-m-d') . '.txt';
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, must-revalidate');
    }

    /**
     * @return array<int,string>
     */
    private function readLinesOrEmpty(string $path): array
    {
        $raw = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return $raw === false ? [] : $raw;
    }
}
