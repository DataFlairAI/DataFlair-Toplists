<?php
/**
 * Phase 8 — Plugin bootstrap seam — test doubles.
 *
 * Pre-declares a minimal `\DataFlair_Toplists` singleton in the global
 * namespace so `Plugin::__construct()` can call `get_instance()` without
 * loading the 5,500-line plugin file.
 */

declare(strict_types=1);

if (!class_exists('PluginBootTestStubs', false)) {
    final class PluginBootTestStubs
    {
        /** Times DataFlair_Toplists::get_instance() has been called since reset. */
        public static int $getInstanceCalls = 0;

        public static function reset(): void
        {
            self::$getInstanceCalls = 0;
        }
    }
}

if (!class_exists('DataFlair_Toplists', false)) {
    /**
     * Minimal test fake standing in for the god-class. Plugin::__construct()
     * only calls get_instance() and stores the result in a typed property, so
     * a class with the right name and a static factory is enough.
     *
     * Phase 9.6 added MenuRegistrar which type-hints `\DataFlair_Toplists` and
     * calls `tests_page()` on the instance. Co-loaded with
     * `MenuRegistrarTestStubs.php` (also declares `DataFlair_Toplists`
     * conditionally) — whichever wins the load race must still expose every
     * method either suite expects, so we keep this stub a superset.
     */
    final class DataFlair_Toplists
    {
        private static ?self $instance = null;

        public static function get_instance(): self
        {
            \PluginBootTestStubs::$getInstanceCalls++;
            if (self::$instance === null) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        public function tests_page(): void
        {
            // No-op — MenuRegistrar wires this as a callable reference only.
        }
    }
}
