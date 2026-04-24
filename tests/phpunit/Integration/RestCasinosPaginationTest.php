<?php
/**
 * H12 regression test: /wp-json/dataflair/v1/toplists/{id}/casinos is
 * paginated, returns a lean per-item payload by default, and preserves the
 * legacy verbose shape under ?full=1 for the block editor.
 *
 * Phase 6 rewrite — execution-path coverage now lives in
 * tests/phpunit/Unit/CasinosControllerTest.php (new in Phase 6). This file
 * keeps the structural guard rails alive by scanning the extracted
 * src/Rest/RestRouter.php + src/Rest/Controllers/CasinosController.php so a
 * future refactor that drops the H12 contract still trips the suite. The
 * union of both files is intentional — the router owns the route arg
 * declaration, the controller owns the handler body.
 *
 * Prior behaviour (now fixed and regression-tested):
 *   - The endpoint decoded the entire toplist JSON blob and returned every
 *     item, unbounded. A 200-item list plus the verbose brand payload meant
 *     ~40 MB of transient PHP memory per concurrent REST call on Sigma's
 *     1 GB cap. Under editor polling this walked straight into OOM.
 *
 * This test enforces:
 *   - Router declares `page`, `per_page`, `full` args with the right
 *     defaults and bounds (structural — src/Rest/RestRouter.php).
 *   - Controller reads page/per_page/full from the request (structural —
 *     src/Rest/Controllers/CasinosController.php).
 *   - Controller applies array_slice(items, offset, per_page).
 *   - Controller emits X-WP-Total + X-WP-TotalPages headers.
 *   - Lean shape returns exactly {id, name, rating, offer_text, logo_url}.
 *   - ?full=1 preserves the legacy verbose keys (itemId, brandId, pros, cons).
 */

use PHPUnit\Framework\TestCase;

class RestCasinosPaginationTest extends TestCase {

    private string $router_source     = '';
    private string $controller_source = '';

    protected function setUp(): void {
        parent::setUp();

        $router_path = DATAFLAIR_PLUGIN_DIR . 'src/Rest/RestRouter.php';
        $this->router_source = (string) file_get_contents($router_path);
        $this->assertNotSame('', $this->router_source, "RestRouter.php must be readable at {$router_path}.");

        $controller_path = DATAFLAIR_PLUGIN_DIR . 'src/Rest/Controllers/CasinosController.php';
        $this->controller_source = (string) file_get_contents($controller_path);
        $this->assertNotSame('', $this->controller_source, "CasinosController.php must be readable at {$controller_path}.");
    }

    // ── Router declares the pagination contract ──────────────────────────

    public function test_router_declares_page_arg_with_default_1(): void {
        $this->assertMatchesRegularExpression(
            "/'page'\s*=>\s*\[[^\]]*'default'\s*=>\s*1/s",
            $this->router_source,
            "RestRouter must declare a 'page' arg with default 1."
        );
    }

    public function test_router_declares_per_page_arg_with_default_20_max_100(): void {
        $this->assertMatchesRegularExpression(
            "/'per_page'\s*=>\s*\[[^\]]*'default'\s*=>\s*20[^\]]*'maximum'\s*=>\s*100/s",
            $this->router_source,
            "RestRouter must declare 'per_page' with default 20 and maximum 100 (H12)."
        );
    }

    public function test_router_declares_full_escape_hatch(): void {
        $this->assertMatchesRegularExpression(
            "/'full'\s*=>\s*\[[^\]]*'default'\s*=>\s*0/s",
            $this->router_source,
            "RestRouter must declare 'full' escape hatch with default 0 — block editor uses ?full=1."
        );
    }

    // ── Controller body honours the contract ─────────────────────────────

    public function test_controller_reads_page_and_per_page_and_full_from_request(): void {
        $this->assertMatchesRegularExpression(
            '/\$request->get_param\([\'"]page[\'"]\)/',
            $this->controller_source,
            'Controller must read the page param from the request.'
        );
        $this->assertMatchesRegularExpression(
            '/\$request->get_param\([\'"]per_page[\'"]\)/',
            $this->controller_source,
            'Controller must read the per_page param from the request.'
        );
        $this->assertMatchesRegularExpression(
            '/\$request->get_param\([\'"]full[\'"]\)/',
            $this->controller_source,
            'Controller must read the full param from the request.'
        );
    }

    public function test_controller_slices_items_for_the_current_page(): void {
        $this->assertMatchesRegularExpression(
            '/array_slice\(\s*\$items\s*,\s*\$offset\s*,\s*\$per_page\s*\)/',
            $this->controller_source,
            'Controller must array_slice the items array by the current page offset — not iterate the full set.'
        );
    }

    public function test_controller_emits_pagination_headers(): void {
        $this->assertStringContainsString(
            'X-WP-Total',
            $this->controller_source,
            "Controller must emit X-WP-Total header."
        );
        $this->assertStringContainsString(
            'X-WP-TotalPages',
            $this->controller_source,
            "Controller must emit X-WP-TotalPages header."
        );
    }

    // ── Lean vs full payload shape ──────────────────────────────────────

    public function test_lean_shape_includes_offer_text_and_logo_url(): void {
        $this->assertMatchesRegularExpression(
            '/[\'"]offer_text[\'"]\s*=>/',
            $this->controller_source,
            'Lean shape must include offer_text key (H12 contract).'
        );
        $this->assertMatchesRegularExpression(
            '/[\'"]logo_url[\'"]\s*=>/',
            $this->controller_source,
            'Lean shape must include logo_url key (H12 contract).'
        );
        $this->assertMatchesRegularExpression(
            '/[\'"]rating[\'"]\s*=>/',
            $this->controller_source,
            'Lean shape must include rating key (H12 contract).'
        );
    }

    public function test_full_branch_preserves_legacy_keys(): void {
        $this->assertMatchesRegularExpression(
            '/\$full\s*\)?\s*\{[\s\S]+?[\'"]itemId[\'"]/',
            $this->controller_source,
            "The ?full=1 branch must retain the legacy itemId key — block editor depends on it."
        );
        $this->assertStringContainsString(
            "'brandId'",
            $this->controller_source,
            "The ?full=1 branch must retain the legacy brandId key."
        );
        $this->assertStringContainsString(
            "'pros'",
            $this->controller_source,
            "The ?full=1 branch must retain the legacy pros key."
        );
        $this->assertStringContainsString(
            "'cons'",
            $this->controller_source,
            "The ?full=1 branch must retain the legacy cons key."
        );
    }

    public function test_controller_uses_injected_prefetch_closure_for_logo_lookup(): void {
        $this->assertMatchesRegularExpression(
            '/\(\$this->prefetchBrandMetas\)\(/',
            $this->controller_source,
            "Controller must invoke the injected prefetch closure — keeps the controller \$wpdb-free."
        );
    }
}
