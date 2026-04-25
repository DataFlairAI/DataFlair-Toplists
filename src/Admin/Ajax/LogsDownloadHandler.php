<?php
/**
 * Phase 9.6 (admin UX redesign) — Stream the DataFlair-filtered debug log
 * as a text/plain download.
 *
 * Uses the admin-ajax.php route (nopriv=false) with `Content-Disposition:
 * attachment` so the admin can save the log locally. The nonce check is
 * done by AjaxRouter before this handler fires. After streaming, calls
 * wp_die() to terminate — normal for download handlers.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Ajax;

use DataFlair\Toplists\Admin\AjaxHandlerInterface;

final class LogsDownloadHandler implements AjaxHandlerInterface
{
    private const DF_MARKER = '[DataFlair]';

    public function handle(array $request): array
    {
        $log_path = $this->resolveLogPath();

        if ($log_path === null || !is_readable($log_path)) {
            // Return a normal JSON error — browser will show it
            return ['success' => false, 'data' => ['message' => 'debug.log not found or not readable.']];
        }

        $raw = file($log_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($raw === false) {
            $raw = [];
        }

        $df_lines = array_filter($raw, static fn(string $l) => str_contains($l, self::DF_MARKER));

        $filename = 'dataflair-debug-' . gmdate('Y-m-d') . '.txt';
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, must-revalidate');
        echo implode(PHP_EOL, $df_lines);
        wp_die();
    }

    private function resolveLogPath(): ?string
    {
        if (defined('WP_DEBUG_LOG') && is_string(WP_DEBUG_LOG) && WP_DEBUG_LOG !== '') {
            return WP_DEBUG_LOG;
        }
        return defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/debug.log' : null;
    }
}
