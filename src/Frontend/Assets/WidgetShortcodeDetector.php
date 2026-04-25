<?php
/**
 * Phase 9.8 — Widget content [dataflair_toplist] detection.
 *
 * Hooks `widget_text` to flag pages that embed the shortcode through a
 * widget; `AlpineJsEnqueuer` reads the flag to decide whether to load
 * Alpine on pages whose main loop has no shortcode.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Frontend\Assets;

final class WidgetShortcodeDetector
{
    /**
     * Set to true once any widget body containing the shortcode is filtered.
     * Static so AlpineJsEnqueuer can read it without sharing instance state.
     */
    public static bool $shortcodeUsed = false;

    public function register(): void
    {
        add_filter('widget_text', [$this, 'check'], 10, 2);
    }

    /**
     * @param string $text
     * @param mixed  $instance unused — kept for filter signature parity
     */
    public function check($text, $instance = null)
    {
        if (is_string($text) && has_shortcode($text, 'dataflair_toplist')) {
            self::$shortcodeUsed = true;
        }
        return $text;
    }

    /** Test seam — reset the static flag between assertions. */
    public static function resetForTests(): void
    {
        self::$shortcodeUsed = false;
    }
}
