<?php
/**
 * Namespace-local WordPress function stub for TableRendererTest.
 *
 * ProsConsResolver (the trait TableRenderer uses) lives in
 * DataFlair\Toplists\Frontend\Render and calls `sanitize_title()`
 * unqualified; PHP resolves that to a namespace-local stub if one exists
 * before falling back to a global one. Previously no stub existed in this
 * namespace at all — the call only worked by accident, falling through to
 * whatever global `sanitize_title` an unrelated Integration test file
 * happened to declare during PHPUnit's test-discovery pass. Declaring it
 * here removes that hidden cross-suite dependency.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Frontend\Render {
    require_once DATAFLAIR_PLUGIN_DIR . 'tests/phpunit/WpSanitizeTitleFake.php';

    if (!function_exists(__NAMESPACE__ . '\\sanitize_title')) {
        function sanitize_title($value) {
            return \WpSanitizeTitleFake::sanitize((string) $value);
        }
    }
}
