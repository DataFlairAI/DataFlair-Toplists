<?php
/**
 * Namespace-local WordPress function stubs for TableRendererTest.
 *
 * TableRenderer, and the ProsConsResolver trait it uses, live in
 * DataFlair\Toplists\Frontend\Render and call esc_html() / sanitize_title()
 * unqualified — PHP resolves those to the current namespace first, then
 * falls back to global. TableRendererTest.php previously declared its own
 * `function esc_html()` guard, but that declaration sat underneath the
 * file's `namespace DataFlair\Toplists\Tests\Unit;` statement, so it
 * actually defined Tests\Unit\esc_html() — a dead stub that never matched
 * TableRenderer's call site. The test only passed when some unrelated
 * Unit-suite file (e.g. ToplistShortcodeTestStubs.php, which declares a
 * real global esc_html()) happened to load earlier in the same process,
 * and sanitize_title() had no such accidental double anywhere in the Unit
 * suite at all. Both are declared correctly namespace-scoped here.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Frontend\Render {
    if (!function_exists(__NAMESPACE__ . '\\esc_html')) {
        function esc_html($value) { return (string) $value; }
    }
    if (!function_exists(__NAMESPACE__ . '\\sanitize_title')) {
        function sanitize_title($value) {
            $value = strtolower(trim((string) $value));
            $value = str_replace('.', '-', $value);
            $value = preg_replace('/[^a-z0-9\-]+/', '-', $value);
            return trim((string) $value, '-');
        }
    }
}
