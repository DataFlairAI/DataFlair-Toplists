<?php
/**
 * Helper class + WordPress function stubs used by RenderIsReadOnlyTest.
 *
 * Responsibilities:
 *   1. Provide an opt-in tripwire: any forbidden function the plugin invokes
 *      during a render pass is recorded via `RenderReadOnlyStubs::record()`.
 *   2. Stub the harmless WordPress helpers that `render-casino-card.php` uses
 *      (esc_html, esc_url, home_url, WP_Post, WP_Query, …) so the template
 *      can execute without a running WordPress installation.
 *   3. Define the constants the template references (DAY_IN_SECONDS).
 *
 * Used by: tests/phpunit/Integration/RenderIsReadOnlyTest.php
 */

class RenderReadOnlyStubs
{
    /** @var array<int, string> */
    private static array $calls = [];

    public static function reset(): void
    {
        self::$calls = [];
    }

    /**
     * Record that a forbidden function was invoked during a render pass.
     * The test asserts this array is empty after the template runs.
     */
    public static function record(string $functionName): void
    {
        self::$calls[] = $functionName;
    }

    /**
     * @return array<int, string>
     */
    public static function getCalls(): array
    {
        return self::$calls;
    }

    /**
     * No-op: forbidden-function stubs are declared unconditionally below
     * at include time. Kept as a public method so the test body reads
     * top-to-bottom as "reset → install → render → assert".
     *
     * @param array<int, string> $names
     */
    public static function installForbiddenFunctionStubs(array $names): void
    {
        // Stubs already declared at file-include time; nothing to do here.
    }
}

// ── Constants used by render-casino-card.php ───────────────────────────────

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

// ── WordPress helper stubs used by render-casino-card.php ──────────────────
// These are read-only / formatting helpers — safe to stub as no-ops or
// identity functions. They are NOT in FORBIDDEN_FUNCTIONS.

if (!function_exists('esc_html')) {
    function esc_html($value) { return (string) $value; }
}
if (!function_exists('esc_attr')) {
    function esc_attr($value) { return (string) $value; }
}
if (!function_exists('esc_url')) {
    function esc_url($value) { return (string) $value; }
}
if (!function_exists('sanitize_title')) {
    function sanitize_title($value) {
        $value = strtolower(trim((string) $value));
        $value = str_replace('.', '-', $value);
        $value = preg_replace('/[^a-z0-9\-]+/', '-', $value);
        return trim((string) $value, '-');
    }
}
if (!function_exists('home_url')) {
    function home_url($path = '') { return 'https://example.test' . $path; }
}
if (!function_exists('post_type_exists')) {
    function post_type_exists($post_type) { return $post_type === 'review'; }
}
if (!function_exists('get_page_by_path')) {
    function get_page_by_path($path, $output = OBJECT, $post_type = 'post') {
        return null;
    }
}
if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key, $single = true) {
        return '';
    }
}
if (!function_exists('wp_reset_postdata')) {
    function wp_reset_postdata() { return; }
}
if (!function_exists('get_post_status')) {
    function get_post_status($post_id) { return false; }
}
if (!function_exists('get_permalink')) {
    function get_permalink($post_id) { return ''; }
}

// ── set_transient: intentionally NOT in FORBIDDEN_FUNCTIONS for Phase 0A. ──
// Phase 0B H10 will relocate tracker transients to sync-time. Until then,
// this is a harmless no-op so the template's tracker-caching block can run.
if (!function_exists('set_transient')) {
    function set_transient($key, $value, $expiration) { return true; }
}

// ── Forbidden-function tripwires ───────────────────────────────────────────
// Each stub records its invocation so RenderIsReadOnlyTest Part B can assert
// that render did not touch them. In a fresh process (@runInSeparateProcess),
// these are the first and only declarations, so they always win. In a shared
// process where a prior test stubbed them harmlessly, the `function_exists`
// guard skips — Part B annotation prevents that.

if (!function_exists('wp_remote_get')) {
    function wp_remote_get(...$args) { RenderReadOnlyStubs::record('wp_remote_get'); return null; }
}
if (!function_exists('wp_remote_post')) {
    function wp_remote_post(...$args) { RenderReadOnlyStubs::record('wp_remote_post'); return null; }
}
if (!function_exists('wp_safe_remote_get')) {
    function wp_safe_remote_get(...$args) { RenderReadOnlyStubs::record('wp_safe_remote_get'); return null; }
}
if (!function_exists('wp_safe_remote_post')) {
    function wp_safe_remote_post(...$args) { RenderReadOnlyStubs::record('wp_safe_remote_post'); return null; }
}
if (!function_exists('wp_insert_post')) {
    function wp_insert_post(...$args) { RenderReadOnlyStubs::record('wp_insert_post'); return null; }
}
if (!function_exists('wp_update_post')) {
    function wp_update_post(...$args) { RenderReadOnlyStubs::record('wp_update_post'); return null; }
}
if (!function_exists('wp_handle_sideload')) {
    function wp_handle_sideload(...$args) { RenderReadOnlyStubs::record('wp_handle_sideload'); return null; }
}
if (!function_exists('wp_handle_upload')) {
    function wp_handle_upload(...$args) { RenderReadOnlyStubs::record('wp_handle_upload'); return null; }
}
if (!function_exists('media_sideload_image')) {
    function media_sideload_image(...$args) { RenderReadOnlyStubs::record('media_sideload_image'); return null; }
}
if (!function_exists('media_handle_sideload')) {
    function media_handle_sideload(...$args) { RenderReadOnlyStubs::record('media_handle_sideload'); return null; }
}
if (!function_exists('download_url')) {
    function download_url(...$args) { RenderReadOnlyStubs::record('download_url'); return null; }
}
if (!function_exists('wp_check_filetype')) {
    function wp_check_filetype(...$args) { RenderReadOnlyStubs::record('wp_check_filetype'); return null; }
}
if (!function_exists('wp_check_filetype_and_ext')) {
    function wp_check_filetype_and_ext(...$args) { RenderReadOnlyStubs::record('wp_check_filetype_and_ext'); return null; }
}
if (!function_exists('update_option')) {
    function update_option(...$args) { RenderReadOnlyStubs::record('update_option'); return null; }
}
if (!function_exists('update_post_meta')) {
    function update_post_meta(...$args) { RenderReadOnlyStubs::record('update_post_meta'); return null; }
}
if (!function_exists('delete_post_meta')) {
    function delete_post_meta(...$args) { RenderReadOnlyStubs::record('delete_post_meta'); return null; }
}

// ── WP_Post / WP_Query mocks ───────────────────────────────────────────────

if (!class_exists('WP_Post')) {
    class WP_Post
    {
        public int $ID = 0;
        public string $post_status = '';
        public string $post_modified = '';

        public function __construct(int $id = 0, string $status = '', string $modified = '')
        {
            $this->ID = $id;
            $this->post_status = $status;
            $this->post_modified = $modified;
        }
    }
}

if (!class_exists('WP_Query')) {
    class WP_Query
    {
        /** @var array<int, WP_Post> */
        public array $posts = [];

        public function __construct(array $args = [])
        {
            $this->posts = [];
        }

        public function have_posts(): bool
        {
            return !empty($this->posts);
        }
    }
}
