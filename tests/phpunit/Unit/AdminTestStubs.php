<?php
/**
 * Phase 9.6 — Namespace-local WordPress function stubs for src/Admin/* tests.
 *
 * PHP resolves an unqualified `add_action(...)` inside the
 * DataFlair\Toplists\Admin namespace to DataFlair\Toplists\Admin\add_action
 * first, falling back to the global only if that isn't defined. Declaring
 * namespace-scoped shims here intercepts the calls without booting WordPress.
 *
 * Use AdminStubs::reset() between tests to clear captured state.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Admin {

    if (!class_exists(AdminStubs::class, false)) {
        final class AdminStubs
        {
            public static array $actions          = [];
            public static array $menuPages        = [];
            public static array $submenuPages     = [];
            public static array $registeredSettings = [];
            public static array $options          = [];
            public static array $echoedNotices    = [];
            public static bool $currentUserCan    = true;

            public static function reset(): void
            {
                self::$actions            = [];
                self::$menuPages          = [];
                self::$submenuPages       = [];
                self::$registeredSettings = [];
                self::$options            = [];
                self::$echoedNotices      = [];
                self::$currentUserCan     = true;
            }
        }
    }
}

namespace DataFlair\Toplists\Admin {

    use DataFlair\Toplists\Tests\Admin\AdminStubs;

    if (!function_exists(__NAMESPACE__ . '\\add_action')) {
        function add_action(string $hook, $callback, int $priority = 10, int $accepted_args = 1): bool
        {
            AdminStubs::$actions[] = [
                'hook'     => $hook,
                'callback' => $callback,
                'priority' => $priority,
            ];
            return true;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\add_menu_page')) {
        function add_menu_page(
            string $page_title,
            string $menu_title,
            string $capability,
            string $menu_slug,
            $callback = '',
            string $icon_url = '',
            $position = null
        ): string {
            AdminStubs::$menuPages[] = [
                'page_title' => $page_title,
                'menu_title' => $menu_title,
                'capability' => $capability,
                'menu_slug'  => $menu_slug,
                'callback'   => $callback,
                'icon_url'   => $icon_url,
                'position'   => $position,
            ];
            return 'toplevel_page_' . $menu_slug;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\add_submenu_page')) {
        function add_submenu_page(
            string $parent_slug,
            string $page_title,
            string $menu_title,
            string $capability,
            string $menu_slug,
            $callback = '',
            $position = null
        ): string {
            AdminStubs::$submenuPages[] = [
                'parent_slug' => $parent_slug,
                'page_title'  => $page_title,
                'menu_title'  => $menu_title,
                'capability'  => $capability,
                'menu_slug'   => $menu_slug,
                'callback'    => $callback,
                'position'    => $position,
            ];
            return $parent_slug . '_page_' . $menu_slug;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\register_setting')) {
        function register_setting(string $option_group, string $option_name, $args = []): void
        {
            AdminStubs::$registeredSettings[] = [
                'group'  => $option_group,
                'option' => $option_name,
                'args'   => is_array($args) ? $args : [],
            ];
        }
    }
}

namespace DataFlair\Toplists\Admin\Notices {

    use DataFlair\Toplists\Tests\Admin\AdminStubs;

    if (!function_exists(__NAMESPACE__ . '\\add_action')) {
        function add_action(string $hook, $callback, int $priority = 10, int $accepted_args = 1): bool
        {
            AdminStubs::$actions[] = [
                'hook'     => $hook,
                'callback' => $callback,
                'priority' => $priority,
            ];
            return true;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\get_option')) {
        function get_option(string $name, $default = false)
        {
            return AdminStubs::$options[$name] ?? $default;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\admin_url')) {
        function admin_url(string $path = '', string $scheme = 'admin'): string
        {
            return 'http://example.test/wp-admin/' . ltrim($path, '/');
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\esc_html')) {
        function esc_html($text): string
        {
            return htmlspecialchars((string) $text, ENT_QUOTES);
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\esc_url')) {
        function esc_url($url): string
        {
            return htmlspecialchars((string) $url, ENT_QUOTES);
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\current_user_can')) {
        function current_user_can(string $capability): bool
        {
            return AdminStubs::$currentUserCan;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\add_query_arg')) {
        function add_query_arg($key, $value = '')
        {
            return 'http://example.test/wp-admin/admin.php?' . $key . '=' . $value;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_nonce_url')) {
        function wp_nonce_url(string $url, string $action = '-1'): string
        {
            return $url . '&_wpnonce=test-nonce';
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_verify_nonce')) {
        function wp_verify_nonce($nonce, $action = -1)
        {
            return $nonce === 'test-nonce' ? 1 : false;
        }
    }
}
