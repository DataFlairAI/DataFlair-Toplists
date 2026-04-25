<?php
/**
 * Phase 9.9 — Namespace-local WP function stubs for Frontend\Content tests.
 *
 * The classes under DataFlair\Toplists\Frontend\Content call unqualified WP
 * functions like post_type_exists() and wp_insert_post(); PHP resolves those
 * to the namespaced version first, so declaring shims here intercepts the
 * calls without Patchwork's "defined too early" guard tripping.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\ReviewContent {

    if (!class_exists(ReviewContentStubs::class, false)) {
        final class ReviewContentStubs
        {
            public static array $insertedPosts   = [];
            public static array $postMetaWrites  = [];
            public static array $loggedErrors    = [];

            /** @var callable|null */
            public static $postTypeExists       = null;
            /** @var callable|null */
            public static $getPageByPath        = null;
            /** @var callable|null */
            public static $sanitizeTitle        = null;
            /** @var callable|null */
            public static $wpInsertPost         = null;
            /** @var callable|null */
            public static $isWpError            = null;
            /** @var callable|null */
            public static $getCurrentUserId     = null;

            public static function reset(): void
            {
                self::$insertedPosts  = [];
                self::$postMetaWrites = [];
                self::$loggedErrors   = [];
                self::$postTypeExists = null;
                self::$getPageByPath  = null;
                self::$sanitizeTitle  = null;
                self::$wpInsertPost   = null;
                self::$isWpError      = null;
                self::$getCurrentUserId = null;
            }
        }
    }
}

namespace DataFlair\Toplists\Frontend\Content {

    use DataFlair\Toplists\Tests\ReviewContent\ReviewContentStubs as S;

    if (!function_exists(__NAMESPACE__ . '\\post_type_exists')) {
        function post_type_exists($post_type)
        {
            if (S::$postTypeExists) {
                return (S::$postTypeExists)($post_type);
            }
            return true;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\get_page_by_path')) {
        function get_page_by_path($slug, $output = OBJECT, $post_type = 'page')
        {
            if (S::$getPageByPath) {
                return (S::$getPageByPath)($slug, $output, $post_type);
            }
            return null;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\sanitize_title')) {
        function sanitize_title($title)
        {
            if (S::$sanitizeTitle) {
                return (S::$sanitizeTitle)($title);
            }
            return strtolower(str_replace(' ', '-', (string) $title));
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\wp_insert_post')) {
        function wp_insert_post($args, $wp_error = false)
        {
            S::$insertedPosts[] = $args;
            if (S::$wpInsertPost) {
                return (S::$wpInsertPost)($args);
            }
            return 444;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\update_post_meta')) {
        function update_post_meta($post_id, $key, $value)
        {
            S::$postMetaWrites[] = ['id' => $post_id, 'key' => $key, 'value' => $value];
            return true;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\is_wp_error')) {
        function is_wp_error($thing)
        {
            if (S::$isWpError) {
                return (S::$isWpError)($thing);
            }
            return false;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\get_current_user_id')) {
        function get_current_user_id()
        {
            if (S::$getCurrentUserId) {
                return (S::$getCurrentUserId)();
            }
            return 1;
        }
    }
    if (!class_exists(__NAMESPACE__ . '\\OBJECT', false) && !defined(__NAMESPACE__ . '\\OBJECT')) {
        // OBJECT global constant from WP core; defined in test bootstrap.
    }
}
