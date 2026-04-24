<?php
/**
 * Namespace-local WordPress function stubs for SaveSettingsHandlerTest.
 *
 * SaveSettingsHandler lives in DataFlair\Toplists\Admin\Handlers and calls
 * update_option / delete_option / esc_url_raw / sanitize_text_field
 * unqualified — PHP resolves those to the current namespace first. The
 * stubs record every write against SaveSettingsHandlerTestStubs::$options
 * so tests can assert exactly what was persisted.
 */

declare(strict_types=1);

namespace {
    if (!class_exists('SaveSettingsHandlerTestStubs')) {
        final class SaveSettingsHandlerTestStubs
        {
            /** @var array<string,mixed> */
            public static array $options = [];

            public static function reset(): void
            {
                self::$options = [];
            }
        }
    }
}

namespace DataFlair\Toplists\Admin\Handlers {
    if (!function_exists(__NAMESPACE__ . '\\update_option')) {
        function update_option($key, $value)
        {
            \SaveSettingsHandlerTestStubs::$options[$key] = $value;
            return true;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\delete_option')) {
        function delete_option($key)
        {
            unset(\SaveSettingsHandlerTestStubs::$options[$key]);
            return true;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\esc_url_raw')) {
        function esc_url_raw($url)
        {
            return $url;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\sanitize_text_field')) {
        function sanitize_text_field($value)
        {
            return is_string($value) ? trim(strip_tags($value)) : $value;
        }
    }
}
