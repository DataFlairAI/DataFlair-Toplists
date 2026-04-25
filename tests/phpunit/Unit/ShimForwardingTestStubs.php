<?php
/**
 * Phase 9 — Shim deprecation test doubles.
 *
 * Mirrors the `DataFlair_Toplists::get_instance()` +
 * `emitDeprecationOncePerCaller()` logic in a testable companion class so
 * we can drive it from controlled callers without loading the 5,600-line
 * plugin file.
 *
 * The implementation is byte-for-byte with `dataflair-toplists.php` at the
 * v2.1.0 flip — **keep the two in sync if you touch either**.
 */

declare(strict_types=1);

if (!class_exists('ShimForwardingTestStubs', false)) {
    final class ShimForwardingTestStubs
    {
        public static function reset(): void
        {
            \DataFlair_Toplists_Phase9_Shim::reset();
        }
    }
}

if (!class_exists('DataFlair_Toplists_Phase9_Shim', false)) {
    /**
     * Testable mirror of the v2.1.0 shim logic. Not part of the production
     * plugin — exists solely so `ShimForwardingTest` can pin the contract.
     */
    final class DataFlair_Toplists_Phase9_Shim
    {
        private static ?self $instance = null;

        /** @var array<string, true> per-request caller de-dup */
        private static array $seen = [];

        public static function reset(): void
        {
            self::$instance = null;
            self::$seen     = [];
        }

        public static function get_instance(): self
        {
            if (null === self::$instance) {
                self::$instance = new self();
            }
            $strict = function_exists('apply_filters')
                ? apply_filters('dataflair_strict_deprecation', true)
                : true;
            if ($strict) {
                self::emitDeprecationOncePerCaller();
            }
            return self::$instance;
        }

        /**
         * Simulates a downstream call site via explicit file:line — lets the
         * test exercise the caller guard logic without spelunking the real
         * debug_backtrace.
         */
        public static function callFromDownstreamFileLine(string $file, int $line): self
        {
            if (null === self::$instance) {
                self::$instance = new self();
            }
            $strict = function_exists('apply_filters')
                ? apply_filters('dataflair_strict_deprecation', true)
                : true;
            if ($strict) {
                self::emitDeprecationForCaller($file, $line);
            }
            return self::$instance;
        }

        /** Convenience — internal-caller path (inside plugin dir). */
        public static function callFromDownstream(): self
        {
            return self::callFromDownstreamFileLine('/var/www/downstream/theme/functions.php', 123);
        }

        private static function emitDeprecationOncePerCaller(): void
        {
            $trace  = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
            $caller = $trace[2] ?? $trace[1] ?? null;
            $file   = $caller['file'] ?? 'unknown';
            $line   = $caller['line'] ?? 0;
            self::emitDeprecationForCaller($file, $line);
        }

        private static function emitDeprecationForCaller(string $file, int $line): void
        {
            if (!function_exists('_deprecated_function')) {
                return;
            }
            $key = $file . ':' . $line;
            if (isset(self::$seen[$key])) {
                return;
            }
            self::$seen[$key] = true;

            $plugin_dir = defined('DATAFLAIR_PLUGIN_DIR') ? DATAFLAIR_PLUGIN_DIR : '';
            if ($plugin_dir !== '' && str_starts_with($file, $plugin_dir)) {
                return;
            }

            _deprecated_function(
                'DataFlair_Toplists::get_instance',
                '2.0.0',
                '\\DataFlair\\Toplists\\Plugin::boot()'
            );
        }
    }
}
