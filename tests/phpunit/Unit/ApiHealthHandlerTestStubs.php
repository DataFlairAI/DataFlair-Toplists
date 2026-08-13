<?php
/**
 * Namespace-local WordPress function stubs for ApiHealthHandlerTest.
 *
 * ApiHealthHandler lives in DataFlair\Toplists\Admin\Ajax and calls
 * set_transient() / update_option() unqualified from persist() — PHP
 * resolves those to the current namespace first, then falls back to
 * global. Brain\Monkey\Functions\when() already covers get_transient /
 * get_option / wp_remote_get / is_wp_error / wp_remote_retrieve_response_code
 * for this test, but nothing stubs the two writer calls, so this test only
 * passed when some other file loaded earlier in the same process (e.g.
 * tests/phpunit/Integration/RenderReadOnlyStubs.php, pulled in only when
 * the Integration suite is part of the run) happened to leave behind a
 * matching global fallback. Declared namespace-locally here instead, so
 * PHP finds these before ever considering the global namespace.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Ajax {
    if (!function_exists(__NAMESPACE__ . '\\set_transient')) {
        function set_transient($key, $value, $expiration) { return true; }
    }
    if (!function_exists(__NAMESPACE__ . '\\update_option')) {
        function update_option($key, $value, $autoload = null) { return true; }
    }
}
