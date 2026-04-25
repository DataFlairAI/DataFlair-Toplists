<?php
/**
 * Phase 9.6 — Lightweight stand-in for the global `DataFlair_Toplists` class so
 * `MenuRegistrar`'s type hint resolves without booting the full plugin.
 *
 * The real class declares ~75 methods and a heavy constructor; for the menu
 * test we only need the symbol to exist with a callable `tests_page` reference.
 *
 * Co-loaded with `PluginBootTestStubs.php` (also declares `DataFlair_Toplists`
 * conditionally). Whichever loads first wins — this stub therefore exposes
 * BOTH surfaces (`tests_page()` for MenuRegistrar AND the `get_instance()`
 * singleton factory + counter PluginBootTest relies on) so the loser of the
 * load race still has every method it needs. Keep both stubs in shape-sync.
 */

declare(strict_types=1);

// Reuse PluginBootTestStubs' counter class if it's around; otherwise create
// a compatible one so a future PluginBootTest run finds what it needs.
if (!class_exists('PluginBootTestStubs', false)) {
    final class PluginBootTestStubs
    {
        public static int $getInstanceCalls = 0;

        public static function reset(): void
        {
            self::$getInstanceCalls = 0;
        }
    }
}

if (!class_exists('DataFlair_Toplists', false)) {
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
            // No-op stub — MenuRegistrar wires this only as a callable reference.
        }

        public function toplist_shortcode($atts = []): string
        {
            // No-op — Phase 9.12 ShortcodeRegistrar accepts this as a deferred
            // callable; the test never actually fires the shortcode.
            return '';
        }

        public function campaign_redirect_handler(): \DataFlair\Toplists\Frontend\Redirect\CampaignRedirectHandler
        {
            return new \DataFlair\Toplists\Frontend\Redirect\CampaignRedirectHandler();
        }
    }
}
