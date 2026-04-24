<?php
/**
 * Resolves the active LoggerInterface for the current request.
 *
 * Default is `ErrorLogLogger('notice')`. Override via:
 *
 *   add_filter('dataflair_logger', fn() => new MyLogger());
 *
 * The filter is resolved once per request and the result cached so every
 * call site pays a single filter lookup, not one per log line.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Logging;

final class LoggerFactory
{
    private static ?LoggerInterface $instance = null;

    public static function get(): LoggerInterface
    {
        if (self::$instance instanceof LoggerInterface) {
            return self::$instance;
        }

        $default = new ErrorLogLogger();

        $resolved = function_exists('apply_filters')
            ? apply_filters('dataflair_logger', $default)
            : $default;

        self::$instance = ($resolved instanceof LoggerInterface) ? $resolved : $default;
        return self::$instance;
    }

    /**
     * Test-only reset. Safe in production because the caching is
     * per-request anyway; useful for unit tests that swap the filter
     * between assertions.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
