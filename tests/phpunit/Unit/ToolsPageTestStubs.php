<?php
/**
 * Namespace-local WordPress function stubs for ToolsPageTest.
 *
 * ToolsPage lives in DataFlair\Toplists\Admin\Pages and calls esc_attr() /
 * esc_html() unqualified — PHP resolves those to the current namespace
 * first, then falls back to global. See TableRendererTestStubs.php's
 * docblock for the exact shape of the mistake this avoids: a stub declared
 * under the test's OWN namespace is a dead stub that never matches the
 * call site, and relying on some unrelated file's global declaration to
 * have loaded first makes the test pass only in full-suite order, not in
 * isolation. Declared correctly namespace-scoped here instead.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Pages {
    if (!function_exists(__NAMESPACE__ . '\\esc_attr')) {
        function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
    }
    if (!function_exists(__NAMESPACE__ . '\\esc_html')) {
        function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
    }
}
