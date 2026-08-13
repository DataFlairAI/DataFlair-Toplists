<?php
/**
 * ActiveLogSource — resolves which file(s) the currently active DataFlair
 * logger writes to, and how to read them.
 *
 * LogsTailHandler and LogsDownloadHandler both need the same answer to
 * "given LoggerFactory::get()'s current logger, where is the data and does
 * it need [DataFlair]-marker filtering" — this class is the single place
 * that dispatch lives, instead of being duplicated per consumer:
 *
 *   - ErrorLogLogger: a shared destination (e.g. wp-content/debug.log).
 *     Prefers the live `ini_get('error_log')` value — what error_log()
 *     actually writes to right now — over the WP_DEBUG_LOG/WP_CONTENT_DIR
 *     convention, since a host/mu-plugin can repoint the ini value after
 *     WordPress's own bootstrap set it. Needs marker filtering, since the
 *     file can carry lines from other plugins/PHP itself.
 *   - FileLogger: its own dedicated file, plus the single rotated backup
 *     (`<path>.1`) it keeps at MAX_BYTES, oldest first, so a recent
 *     rotation doesn't silently hide everything written just before it.
 *     Every line already belongs to DataFlair — no marker filtering.
 *   - Any other logger: no built-in read support.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Support;

use DataFlair\Toplists\Logging\ErrorLogLogger;
use DataFlair\Toplists\Logging\FileLogger;
use DataFlair\Toplists\Logging\LoggerFactory;
use DataFlair\Toplists\Logging\NullLogger;

final class ActiveLogSource
{
    public const DF_MARKER = '[DataFlair]';

    /**
     * @return array{paths: array<int,string>, filterMarker: bool, error: ?string}
     */
    public function resolve(): array
    {
        $logger = LoggerFactory::get();

        if ($logger instanceof ErrorLogLogger) {
            return $this->resolveErrorLog();
        }

        if ($logger instanceof FileLogger) {
            return $this->resolveFileLogger($logger);
        }

        return ['paths' => [], 'filterMarker' => false, 'error' => $this->unsupportedMessage($logger)];
    }

    /**
     * @return array{paths: array<int,string>, filterMarker: bool, error: ?string}
     */
    private function resolveErrorLog(): array
    {
        if (!defined('WP_DEBUG_LOG') || !WP_DEBUG_LOG) {
            return ['paths' => [], 'filterMarker' => true, 'error' => 'Enable WP_DEBUG_LOG in wp-config.php to capture log output.'];
        }

        $path = $this->resolveErrorLogPath();
        if ($path === null || !is_readable($path)) {
            return ['paths' => [], 'filterMarker' => true, 'error' => 'debug.log not found or not readable.'];
        }

        return ['paths' => [$path], 'filterMarker' => true, 'error' => null];
    }

    /**
     * @return array{paths: array<int,string>, filterMarker: bool, error: ?string}
     */
    private function resolveFileLogger(FileLogger $logger): array
    {
        $path = $logger->path();
        if ($path === '' || !is_readable($path)) {
            return ['paths' => [], 'filterMarker' => false, 'error' => 'dataflair-sync.log not found or not readable.'];
        }

        $paths   = [$path];
        $rotated = $logger->rotatedPath();
        if (is_readable($rotated)) {
            array_unshift($paths, $rotated); // oldest content first
        }

        return ['paths' => $paths, 'filterMarker' => false, 'error' => null];
    }

    /**
     * Prefer the live PHP error_log destination (what error_log() actually
     * writes to right now) over the WP_DEBUG_LOG/WP_CONTENT_DIR convention,
     * which only reflects where WordPress's own bootstrap pointed it.
     */
    private function resolveErrorLogPath(): ?string
    {
        $ini_path = (string) ini_get('error_log');
        if ($ini_path !== '' && is_file($ini_path)) {
            return $ini_path;
        }

        if (is_string(WP_DEBUG_LOG) && WP_DEBUG_LOG !== '') {
            return WP_DEBUG_LOG;
        }
        return defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/debug.log' : null;
    }

    private function unsupportedMessage(object $logger): string
    {
        if ($logger instanceof NullLogger) {
            return 'Logging is disabled (NullLogger is active) — there is nothing to show.';
        }
        return sprintf('Log viewer does not support the active logger (%s).', get_class($logger));
    }
}
