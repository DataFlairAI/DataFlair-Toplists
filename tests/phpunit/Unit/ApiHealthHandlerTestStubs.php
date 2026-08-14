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
 *
 * update_option() is already stubbed for this exact namespace by
 * SaveSettingsHandlerTestStubs.php (a stateful recorder SaveSettingsHandlerTest
 * asserts against directly) — required here rather than re-declared, so the
 * two files can't race for the function_exists() guard and silently disable
 * SaveSettingsHandlerTestStubs.php's recording depending on load order (which
 * this file would always win, since PHPUnit discovers "ApiHealthHandlerTest.php"
 * before "SaveSettingsHandlerTest.php"). Only set_transient() is new here.
 *
 * set_transient() records into ApiHealthHandlerTestStubs::$transients (same
 * recorder pattern as SaveSettingsHandlerTestStubs::$options) so tests can
 * assert what ApiHealthHandler::persist() actually wrote, not just what
 * handle() returned — a plain `return true;` no-op left that write path
 * completely uncovered.
 */

declare(strict_types=1);

namespace {
    require_once __DIR__ . '/SaveSettingsHandlerTestStubs.php';

    if (!class_exists('ApiHealthHandlerTestStubs')) {
        final class ApiHealthHandlerTestStubs
        {
            /** @var array<string,array{value:mixed,expiration:int}> */
            public static array $transients = [];

            public static function reset(): void
            {
                self::$transients = [];
            }
        }
    }
}

namespace DataFlair\Toplists\Admin\Ajax {
    if (!function_exists(__NAMESPACE__ . '\\set_transient')) {
        function set_transient($key, $value, $expiration = 0)
        {
            \ApiHealthHandlerTestStubs::$transients[$key] = ['value' => $value, 'expiration' => $expiration];
            return true;
        }
    }
}
