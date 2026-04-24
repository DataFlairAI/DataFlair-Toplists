<?php
/**
 * H7 + H8 regression test: render_casino_card() must not run a stack of
 * cascading $wpdb->prepare calls per card. The shortcode handler prefetches
 * every card's brand row in a single (or at most three) SQL round-trip, then
 * passes the map into render_casino_card() as its 5th argument.
 *
 * This test is a structural scan of the plugin file. It enforces:
 *   - render_casino_card() accepts a 5th $brand_meta_map parameter.
 *   - The shortcode-handler render loop calls prefetch_brand_metas_for_items()
 *     before the foreach.
 *   - The new helpers prefetch_brand_metas_for_items() and
 *     lookup_brand_meta_from_map() are defined.
 *   - find_review_posts_by_brand_metas() exists as the H8 defensive backstop.
 *   - The map-driven render branch uses the prefetched row in place of the
 *     legacy per-card $wpdb->prepare cascade.
 *
 * Execution-path coverage of the query count lands in Phase 2 when the render
 * concern is extracted into CardRenderer / TableRenderer against an SQLite
 * fixture. Until then, the source-scan pattern mirrors the other H* tests in
 * this suite.
 */

use PHPUnit\Framework\TestCase;

class RenderBatchQueryCountTest extends TestCase {

    private string $source = '';

    protected function setUp(): void {
        parent::setUp();
        $path = DATAFLAIR_PLUGIN_DIR . 'dataflair-toplists.php';
        $this->source = (string) file_get_contents($path);
        $this->assertNotSame('', $this->source, "Plugin file must be readable at {$path}.");
    }

    // ── New helpers defined ───────────────────────────────────────────────

    public function test_prefetch_brand_metas_helper_is_defined(): void {
        $this->assertMatchesRegularExpression(
            '/function\s+prefetch_brand_metas_for_items\s*\(/',
            $this->source,
            'prefetch_brand_metas_for_items() must be defined — it is the H7 batched prefetch entry point.'
        );
    }

    public function test_lookup_brand_meta_from_map_helper_is_defined(): void {
        $this->assertMatchesRegularExpression(
            '/function\s+lookup_brand_meta_from_map\s*\(/',
            $this->source,
            'lookup_brand_meta_from_map() must be defined — render_casino_card() resolves a card\'s brand row through it.'
        );
    }

    public function test_find_review_posts_by_brand_metas_helper_is_defined(): void {
        $this->assertMatchesRegularExpression(
            '/function\s+find_review_posts_by_brand_metas\s*\(/',
            $this->source,
            'find_review_posts_by_brand_metas() must exist — H8 defensive JOIN-based backstop for review CPT lookup.'
        );
    }

    // ── Shortcode handler prefetches before the render loop ─────────────

    public function test_shortcode_handler_prefetches_brand_metas_before_loop(): void {
        $this->assertMatchesRegularExpression(
            '/\$brand_meta_map\s*=\s*\$this->prefetch_brand_metas_for_items\s*\(\s*\$items\s*\)\s*;/',
            $this->source,
            'Shortcode render path must call prefetch_brand_metas_for_items($items) once before iterating items.'
        );
    }

    public function test_render_casino_card_call_site_passes_meta_map(): void {
        $this->assertMatchesRegularExpression(
            '/\$this->render_casino_card\s*\(\s*\$item\s*,\s*\$atts\[[\'"]id[\'"]\]\s*,\s*\$customizations\s*,\s*\$pros_cons_data\s*,\s*\$brand_meta_map\s*\)/',
            $this->source,
            'render_casino_card() call in the shortcode render loop must pass $brand_meta_map as the 5th argument.'
        );
    }

    // ── render_casino_card signature accepts the map ────────────────────

    public function test_render_casino_card_signature_accepts_brand_meta_map(): void {
        $signature = $this->extractMethodSignature('render_casino_card');
        $this->assertMatchesRegularExpression(
            '/\$brand_meta_map\s*=\s*null/',
            $signature,
            'render_casino_card() must declare $brand_meta_map = null as its 5th parameter. Signature: ' . $signature
        );
    }

    public function test_render_casino_card_consults_meta_map_before_falling_back(): void {
        $body = $this->extractMethodBody('render_casino_card');
        $this->assertStringContainsString(
            '$this->lookup_brand_meta_from_map(',
            $body,
            'render_casino_card() must call lookup_brand_meta_from_map() on the prefetched $brand_meta_map.'
        );
    }

    // ── Prefetch helper uses a single IN (...) query per key dimension ──

    public function test_prefetch_helper_uses_single_in_query_per_key(): void {
        $body = $this->extractMethodBody('prefetch_brand_metas_for_items');
        $this->assertNotEmpty($body, 'prefetch_brand_metas_for_items() body must be present in plugin file.');

        $in_matches = preg_match_all(
            '/WHERE\s+\w+\s+IN\s*\(\s*\$placeholders\s*\)/i',
            $body
        );
        $this->assertGreaterThanOrEqual(
            3,
            $in_matches,
            'prefetch_brand_metas_for_items() must run IN (...) batched queries (one per key dimension: api_brand_id / slug / name).'
        );
    }

    public function test_prefetch_helper_selects_all_required_columns(): void {
        $body = $this->extractMethodBody('prefetch_brand_metas_for_items');
        foreach (['local_logo_url', 'cached_review_post_id', 'review_url_override'] as $col) {
            $this->assertStringContainsString(
                $col,
                $body,
                "Prefetch helper must select `$col` — render_casino_card() relies on it."
            );
        }
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

    private function extractMethodSignature(string $methodName): string {
        $pattern = '/function\s+' . preg_quote($methodName, '/') . '\s*\(/';
        if (!preg_match($pattern, $this->source, $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }
        $start = $m[0][1];
        $openParen = strpos($this->source, '(', $start);
        if ($openParen === false) return '';

        $depth = 1;
        $i     = $openParen + 1;
        $len   = strlen($this->source);
        while ($i < $len && $depth > 0) {
            $ch = $this->source[$i];
            if ($ch === '(') $depth++;
            elseif ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    return substr($this->source, $start, ($i + 1) - $start);
                }
            }
            $i++;
        }
        return '';
    }
}
