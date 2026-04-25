<?php
/**
 * Phase 9.8 — Namespace-local WP function stubs for Frontend\Assets tests.
 *
 * PHP resolves an unqualified `add_action(...)` inside
 * DataFlair\Toplists\Frontend\Assets to the namespaced function first.
 * Declaring shims under that namespace intercepts the calls without
 * loading WordPress.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\FrontendAssets {

    if (!class_exists(FrontendAssetsStubs::class, false)) {
        final class FrontendAssetsStubs
        {
            public static array $actions          = [];
            public static array $filters          = [];
            public static array $enqueuedStyles   = [];
            public static array $enqueuedScripts  = [];
            public static array $registeredScripts = []; // by handle
            public static array $scriptIs         = []; // [handle => ['enqueued' => bool, 'registered' => bool]]
            public static ?object $wpScripts      = null;
            public static ?object $wpQuery        = null;
            public static $post                   = null;
            public static array $hasShortcode     = []; // ['needle' => ['content' => bool, ...]]
            public static array $hasBlock         = []; // similar
            public static array $filterMap        = []; // applied filter overrides

            public static function reset(): void
            {
                self::$actions          = [];
                self::$filters          = [];
                self::$enqueuedStyles   = [];
                self::$enqueuedScripts  = [];
                self::$registeredScripts = [];
                self::$scriptIs         = [];
                self::$wpScripts        = null;
                self::$wpQuery          = null;
                self::$post             = null;
                self::$hasShortcode     = [];
                self::$hasBlock         = [];
                self::$filterMap        = [];
            }
        }
    }
}

namespace DataFlair\Toplists\Frontend\Assets {

    use DataFlair\Toplists\Tests\FrontendAssets\FrontendAssetsStubs as S;

    if (!function_exists(__NAMESPACE__ . '\\add_action')) {
        function add_action(string $hook, $callback, int $priority = 10, int $accepted_args = 1): bool
        {
            S::$actions[] = ['hook' => $hook, 'callback' => $callback, 'priority' => $priority];
            return true;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\add_filter')) {
        function add_filter(string $hook, $callback, int $priority = 10, int $accepted_args = 1): bool
        {
            S::$filters[] = ['hook' => $hook, 'callback' => $callback, 'priority' => $priority];
            return true;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_enqueue_style')) {
        function wp_enqueue_style(string $handle, string $src = '', array $deps = [], $version = false, string $media = 'all'): void
        {
            S::$enqueuedStyles[] = compact('handle', 'src', 'deps', 'version', 'media');
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_enqueue_script')) {
        function wp_enqueue_script(string $handle, string $src = '', array $deps = [], $version = false, bool $in_footer = false): void
        {
            S::$enqueuedScripts[] = compact('handle', 'src', 'deps', 'version', 'in_footer');
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_script_is')) {
        function wp_script_is(string $handle, string $list = 'enqueued'): bool
        {
            return (bool) (S::$scriptIs[$handle][$list] ?? false);
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\has_shortcode')) {
        function has_shortcode($content, string $tag): bool
        {
            $key = is_string($content) ? $content : (is_object($content) && isset($content->post_content) ? $content->post_content : '');
            return (bool) (S::$hasShortcode[$key][$tag] ?? false);
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\has_block')) {
        function has_block(string $block, $post = null): bool
        {
            $key = is_object($post) && isset($post->post_content) ? $post->post_content : (string) $post;
            return (bool) (S::$hasBlock[$key][$block] ?? false);
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\apply_filters')) {
        function apply_filters(string $hook, $value, ...$args)
        {
            return S::$filterMap[$hook] ?? $value;
        }
    }
}
