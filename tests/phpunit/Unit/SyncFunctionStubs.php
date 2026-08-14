<?php
/**
 * Namespace-local WordPress function stubs for the Phase 3 Sync unit tests.
 *
 * Brain Monkey / Patchwork cannot redefine functions that have already been
 * declared as real PHP functions (e.g. RenderReadOnlyStubs.php during the
 * integration suite). To keep the unit tests runnable in the full suite
 * without poisoning other tests (ApiClientTest uses Brain Monkey to stub
 * `get_option` in the global namespace), we declare these stubs INSIDE the
 * `DataFlair\Toplists\Sync` namespace. PHP resolves unqualified function
 * calls from within a namespace to that namespace first, then global — so
 * the services under test find these, while code in other namespaces (e.g.
 * `DataFlair\Toplists\Http\ApiClient`) is unaffected and can still rely on
 * Brain Monkey's global stubs.
 *
 * get_option/update_option/*_transient read and write SyncFunctionStubsStore
 * so tests that need to assert on persisted values (SyncHistoryRecorderTest)
 * can, while tests that only need these calls to not fatal (BrandSyncServiceTest,
 * ToplistSyncServiceTest) are unaffected — they never inspect the store.
 */

namespace {
    if (!class_exists('SyncFunctionStubsStore')) {
        final class SyncFunctionStubsStore
        {
            /** @var array<string, mixed> */
            public static array $options = [];

            /** @var array<string, mixed> */
            public static array $transients = [];

            public static function reset(): void
            {
                self::$options = [];
                self::$transients = [];
            }
        }
    }
}

namespace DataFlair\Toplists\Sync {
    if (!function_exists(__NAMESPACE__ . '\\set_transient')) {
        function set_transient($key, $value, $expiration = 0)
        {
            \SyncFunctionStubsStore::$transients[$key] = $value;
            return true;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\get_transient')) {
        function get_transient($key)
        {
            return \SyncFunctionStubsStore::$transients[$key] ?? false;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\delete_transient')) {
        function delete_transient($key)
        {
            unset(\SyncFunctionStubsStore::$transients[$key]);
            return true;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\get_option')) {
        function get_option($key, $default = '')
        {
            return \SyncFunctionStubsStore::$options[$key] ?? $default;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\update_option')) {
        function update_option($key, $value, $autoload = null)
        {
            \SyncFunctionStubsStore::$options[$key] = $value;
            return true;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\wp_json_encode')) {
        function wp_json_encode($value) { return json_encode($value); }
    }
    if (!function_exists(__NAMESPACE__ . '\\sanitize_title')) {
        function sanitize_title($value) {
            return strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string) $value));
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\current_time')) {
        function current_time($type) {
            return $type === 'mysql' ? '2026-01-01 00:00:00' : time();
        }
    }
}
