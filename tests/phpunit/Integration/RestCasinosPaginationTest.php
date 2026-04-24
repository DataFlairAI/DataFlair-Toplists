<?php
/**
 * H12 regression test: /wp-json/dataflair/v1/toplists/{id}/casinos is
 * paginated, returns a lean per-item payload by default, and preserves the
 * legacy verbose shape under ?full=1 for the block editor.
 *
 * Prior behaviour:
 *   - The endpoint decoded the entire toplist JSON blob and returned every
 *     item, unbounded. A 200-item list plus the verbose brand payload meant
 *     ~40 MB of transient PHP memory per concurrent REST call on Sigma's
 *     1 GB cap. Under editor polling this walked straight into OOM.
 *
 * This is a structural scan of the plugin file. It enforces:
 *   - The route declares `page`, `per_page`, `full` args with the right
 *     defaults and bounds.
 *   - The handler applies array_slice(items, offset, per_page) on the paged
 *     slice — not the full list.
 *   - The handler emits X-WP-Total + X-WP-TotalPages pagination headers.
 *   - The lean shape returns exactly {id, name, rating, offer_text, logo_url}.
 *   - ?full=1 preserves the legacy verbose keys (itemId, brandId, pros, cons).
 *
 * Execution-path coverage lands in Phase 6 when the REST surface is
 * extracted into a dedicated controller class.
 */

use PHPUnit\Framework\TestCase;

class RestCasinosPaginationTest extends TestCase {

    private string $source           = '';
    private string $route_args       = '';
    private string $handler_body     = '';

    protected function setUp(): void {
        parent::setUp();
        $path = DATAFLAIR_PLUGIN_DIR . 'dataflair-toplists.php';
        $this->source = (string) file_get_contents($path);
        $this->assertNotSame('', $this->source, "Plugin file must be readable at {$path}.");

        // Extract the args block attached to the /toplists/{id}/casinos route.
        if (preg_match(
            '/register_rest_route\(\s*[\'"]dataflair\/v1[\'"]\s*,\s*[\'"]\/toplists\/\(\?P<id>\\\d\+\)\/casinos[\'"]\s*,\s*array\((.+?)\)\);/s',
            $this->source,
            $m
        )) {
            $this->route_args = $m[1];
        }
        $this->handler_body = $this->extractMethodBody('get_toplist_casinos_rest');
    }

    // ── Route declaration surfaces the pagination contract ───────────────

    public function test_route_declares_page_arg_with_default_1(): void {
        $this->assertNotSame('', $this->route_args, 'casinos route args block must be extractable.');
        $this->assertMatchesRegularExpression(
            "/'page'\s*=>\s*array\([^)]*'default'\s*=>\s*1/s",
            $this->route_args,
            "casinos route must declare a 'page' arg with default 1."
        );
    }

    public function test_route_declares_per_page_arg_with_default_20_max_100(): void {
        $this->assertMatchesRegularExpression(
            "/'per_page'\s*=>\s*array\([^)]*'default'\s*=>\s*20[^)]*'maximum'\s*=>\s*100/s",
            $this->route_args,
            "casinos route must declare 'per_page' with default 20 and maximum 100 (H12)."
        );
    }

    public function test_route_declares_full_escape_hatch(): void {
        $this->assertMatchesRegularExpression(
            "/'full'\s*=>\s*array\([^)]*'default'\s*=>\s*0/s",
            $this->route_args,
            "casinos route must declare 'full' escape hatch with default 0 — block editor uses ?full=1."
        );
    }

    // ── Handler paginates + emits WP REST headers ────────────────────────

    public function test_handler_reads_page_and_per_page_and_full_from_request(): void {
        $this->assertNotSame('', $this->handler_body, 'get_toplist_casinos_rest() body must be extractable.');

        $this->assertMatchesRegularExpression(
            '/\$request->get_param\([\'"]page[\'"]\)/',
            $this->handler_body,
            'Handler must read the page param from the request.'
        );
        $this->assertMatchesRegularExpression(
            '/\$request->get_param\([\'"]per_page[\'"]\)/',
            $this->handler_body,
            'Handler must read the per_page param from the request.'
        );
        $this->assertMatchesRegularExpression(
            '/\$request->get_param\([\'"]full[\'"]\)/',
            $this->handler_body,
            'Handler must read the full param from the request.'
        );
    }

    public function test_handler_slices_items_for_the_current_page(): void {
        $this->assertMatchesRegularExpression(
            '/array_slice\(\s*\$items\s*,\s*\$offset\s*,\s*\$per_page\s*\)/',
            $this->handler_body,
            'Handler must array_slice the items array by the current page offset — not iterate the full set.'
        );
    }

    public function test_handler_emits_pagination_headers(): void {
        $this->assertStringContainsString(
            'X-WP-Total',
            $this->handler_body,
            "Handler must emit X-WP-Total header."
        );
        $this->assertStringContainsString(
            'X-WP-TotalPages',
            $this->handler_body,
            "Handler must emit X-WP-TotalPages header."
        );
    }

    // ── Lean vs full payload shape ──────────────────────────────────────

    public function test_lean_shape_includes_offer_text_and_logo_url(): void {
        $this->assertMatchesRegularExpression(
            '/[\'"]offer_text[\'"]\s*=>/',
            $this->handler_body,
            'Lean shape must include offer_text key (H12 contract).'
        );
        $this->assertMatchesRegularExpression(
            '/[\'"]logo_url[\'"]\s*=>/',
            $this->handler_body,
            'Lean shape must include logo_url key (H12 contract).'
        );
        $this->assertMatchesRegularExpression(
            '/[\'"]rating[\'"]\s*=>/',
            $this->handler_body,
            'Lean shape must include rating key (H12 contract).'
        );
    }

    public function test_full_branch_preserves_legacy_keys(): void {
        $this->assertMatchesRegularExpression(
            '/\$full\s*\)?\s*\{[\s\S]+?[\'"]itemId[\'"]/',
            $this->handler_body,
            "The ?full=1 branch must retain the legacy itemId key — block editor depends on it."
        );
        $this->assertStringContainsString(
            "'brandId'",
            $this->handler_body,
            "The ?full=1 branch must retain the legacy brandId key."
        );
        $this->assertStringContainsString(
            "'pros'",
            $this->handler_body,
            "The ?full=1 branch must retain the legacy pros key."
        );
        $this->assertStringContainsString(
            "'cons'",
            $this->handler_body,
            "The ?full=1 branch must retain the legacy cons key."
        );
    }

    public function test_handler_reuses_prefetch_brand_metas_for_logo_lookup(): void {
        $this->assertStringContainsString(
            '$this->prefetch_brand_metas_for_items(',
            $this->handler_body,
            "Handler must reuse prefetch_brand_metas_for_items() to populate logo_url — no N extra queries."
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function extractMethodBody(string $methodName): string {
        $signaturePattern = '/function\s+' . preg_quote($methodName, '/') . '\s*\(/';
        if (!preg_match($signaturePattern, $this->source, $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        $start = $m[0][1];
        $openBrace = strpos($this->source, '{', $start);
        if ($openBrace === false) return '';

        $depth = 1;
        $i     = $openBrace + 1;
        $len   = strlen($this->source);
        while ($i < $len && $depth > 0) {
            $ch = $this->source[$i];
            if ($ch === '{') $depth++;
            elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($this->source, $openBrace + 1, $i - $openBrace - 1);
                }
            }
            $i++;
        }
        return '';
    }
}
