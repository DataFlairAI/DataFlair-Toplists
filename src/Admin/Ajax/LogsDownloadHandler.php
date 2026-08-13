<?php
/**
 * Phase 9.6 (admin UX redesign) — Stream the active DataFlair log as a
 * text/plain download.
 *
 * The active logger is resolved via LoggerFactory and dispatched by type,
 * mirroring LogsTailHandler and `wp dataflair logs`
 * (includes/Cli/LogsCommand.php):
 *   - ErrorLogLogger (shared destination, e.g. wp-content/debug.log):
 *     read WP_DEBUG_LOG and keep only lines carrying the `[DataFlair]`
 *     marker it writes via PHP's error_log().
 *   - FileLogger (default since the persistent dataflair-sync.log
 *     feature): read the logger's own dedicated file directly — every
 *     line already belongs to DataFlair.
 *   - Any other logger: no built-in download support.
 *
 * Uses the admin-ajax.php route (nopriv=false) with `Content-Disposition:
 * attachment` so the admin can save the log locally. The nonce check is
 * done by AjaxRouter before this handler fires. After streaming, calls
 * wp_die() to terminate — normal for download handlers.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Ajax;

use DataFlair\Toplists\Admin\AjaxHandlerInterface;
use DataFlair\Toplists\Logging\ErrorLogLogger;
use DataFlair\Toplists\Logging\FileLogger;
use DataFlair\Toplists\Logging\LoggerFactory;

final class LogsDownloadHandler implements AjaxHandlerInterface
{
    private const DF_MARKER = '[DataFlair]';

    public function handle(array $request): array
    {
        $logger = LoggerFactory::get();

        if ($logger instanceof ErrorLogLogger) {
            return $this->downloadErrorLog();
        }

        if ($logger instanceof FileLogger) {
            return $this->downloadFileLogger($logger);
        }

        return ['success' => false, 'data' => ['message' => sprintf(
            'Log viewer does not support the active logger (%s).',
            get_class($logger)
        )]];
    }

    private function downloadErrorLog(): array
    {
        $log_path = $this->resolveLogPath();

        if ($log_path === null || !is_readable($log_path)) {
            return ['success' => false, 'data' => ['message' => 'debug.log not found or not readable.']];
        }

        $raw = file($log_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($raw === false) {
            $raw = [];
        }

        $lines = array_filter($raw, static fn(string $l) => str_contains($l, self::DF_MARKER));

        return $this->stream($lines);
    }

    private function downloadFileLogger(FileLogger $logger): array
    {
        $path = $logger->path();

        if ($path === '' || !is_readable($path)) {
            return ['success' => false, 'data' => ['message' => 'dataflair-sync.log not found or not readable.']];
        }

        $raw = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($raw === false) {
            $raw = [];
        }

        return $this->stream($raw);
    }

    /**
     * Streams the given lines as a text/plain download and terminates via
     * wp_die(). The return is unreachable in production; it exists only so
     * the method satisfies its declared return type for tests that stub
     * wp_die() as a no-op.
     *
     * @param array<int,string> $lines
     */
    private function stream(array $lines): array
    {
        $filename = 'dataflair-debug-' . gmdate('Y-m-d') . '.txt';
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, must-revalidate');
        echo implode(PHP_EOL, $lines);
        wp_die();

        return ['success' => true, 'data' => []];
    }

    private function resolveLogPath(): ?string
    {
        if (defined('WP_DEBUG_LOG') && is_string(WP_DEBUG_LOG) && WP_DEBUG_LOG !== '') {
            return WP_DEBUG_LOG;
        }
        return defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/debug.log' : null;
    }
}
