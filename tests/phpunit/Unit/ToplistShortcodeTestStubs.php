<?php
/**
 * Phase 9.12 — Global WordPress function stubs for ToplistShortcodeTest.
 *
 * Patchwork (which Brain Monkey uses) can only re-route a function on its
 * first declaration. If we tried to `Functions\when('esc_html')` after
 * another loaded test file already declared a global `esc_html()`, Patchwork
 * throws `DefinedTooEarly`. So we declare these helpers as plain global
 * functions here, guarded by `function_exists` to play nicely with any
 * other test stub that wins the load race.
 *
 * Both functions are no-ops sufficient for the shortcode unit test:
 *   - `esc_html()` returns its argument cast to string
 *   - `wp_parse_args()` merges defaults left-then-args-right
 *
 * Loaded by ToplistShortcodeTest.php once at file load time.
 */

declare(strict_types=1);

if (!function_exists('esc_html')) {
    function esc_html($value)
    {
        return (string) $value;
    }
}

if (!function_exists('wp_parse_args')) {
    function wp_parse_args($args, $defaults = []): array
    {
        if (!is_array($args)) {
            $args = [];
        }
        if (!is_array($defaults)) {
            $defaults = [];
        }
        return array_merge($defaults, $args);
    }
}
