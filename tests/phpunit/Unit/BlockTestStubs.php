<?php
/**
 * Phase 7 — Namespace-local WordPress function stubs for src/Block/* tests.
 *
 * PHP resolves an unqualified `register_block_type(...)` inside the
 * DataFlair\Toplists\Block namespace to DataFlair\Toplists\Block\register_block_type
 * first, falling back to the global only if that isn't defined. By declaring
 * namespace-scoped shims here we can intercept the calls without having to
 * run inside a real WordPress.
 *
 * Use BlockStubs::reset() between tests to clear captured state.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Block {

    if (!class_exists(BlockStubs::class, false)) {
        final class BlockStubs
        {
            public static array $registered     = [];
            public static array $enqueuedStyles = [];
            public static array $actions        = [];

            public static function reset(): void
            {
                self::$registered     = [];
                self::$enqueuedStyles = [];
                self::$actions        = [];
            }
        }
    }
}

namespace DataFlair\Toplists\Block {

    use DataFlair\Toplists\Tests\Block\BlockStubs;

    if (!function_exists(__NAMESPACE__ . '\\register_block_type')) {
        function register_block_type(string $block_json, array $args = []): void
        {
            BlockStubs::$registered[] = ['block_json' => $block_json, 'args' => $args];
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\function_exists')) {
        function function_exists(string $name): bool
        {
            if ($name === 'register_block_type') {
                return true;
            }
            return \function_exists($name);
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\add_action')) {
        function add_action(string $hook, $callback, int $priority = 10, int $accepted_args = 1): bool
        {
            BlockStubs::$actions[] = ['hook' => $hook, 'callback' => $callback, 'priority' => $priority];
            return true;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_enqueue_style')) {
        function wp_enqueue_style(string $handle, string $src = '', array $deps = [], $ver = null, string $media = 'all'): void
        {
            BlockStubs::$enqueuedStyles[] = [
                'handle' => $handle,
                'src'    => $src,
                'deps'   => $deps,
                'ver'    => $ver,
                'media'  => $media,
            ];
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_parse_args')) {
        function wp_parse_args($args, array $defaults = []): array
        {
            $args = is_array($args) ? $args : [];
            return array_merge($defaults, $args);
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\esc_html__')) {
        function esc_html__(string $text, string $domain = 'default'): string
        {
            return $text;
        }
    }
}
