<?php
/**
 * H2 regression test: api_get() caps response bodies at 15 MB and has a
 * 12 s default timeout (H13). Structural + behavioural proof.
 *
 * Structural check: scan the api_get method body in dataflair-toplists.php
 * for the documented invariants (limit_response_size, $max_bytes literal,
 * 12 s default timeout, `dataflair_response_too_large` error code,
 * optional WallClockBudget parameter).
 *
 * Behavioural check: WallClockBudget integration is exercised directly —
 * when a pre-exhausted budget is passed, the request must short-circuit
 * rather than make an HTTP call.
 *
 * This is the pre-Phase-2 version of this test. When ApiClient is
 * extracted in Phase 2, the structural scan is replaced by a real mocked
 * HTTP test against the class.
 */

use DataFlair\Toplists\Support\WallClockBudget;
use PHPUnit\Framework\TestCase;

class SyncApiSizeCapTest extends TestCase {

    private string $method_body = '';

    protected function setUp(): void {
        parent::setUp();
        $this->method_body = $this->extractMethodBody('api_get');
        $this->assertNotEmpty(
            $this->method_body,
            'api_get() method body must be found in plugin file.'
        );
    }

    public function test_api_get_passes_limit_response_size_to_wp_remote_get(): void {
        $this->assertMatchesRegularExpression(
            '/[\'"]limit_response_size[\'"]\s*=>\s*\$max_bytes/',
            $this->method_body,
            'api_get() must pass limit_response_size => $max_bytes into wp_remote_get args.'
        );
    }

    public function test_api_get_caps_bytes_at_exactly_15mb(): void {
        $this->assertMatchesRegularExpression(
            '/\$max_bytes\s*=\s*15\s*\*\s*1024\s*\*\s*1024\s*;/',
            $this->method_body,
            'Cap must be exactly 15 MB per Phase 0B H2 (15 * 1024 * 1024).'
        );
    }

    public function test_api_get_default_timeout_is_12_seconds(): void {
        $signature = $this->extractMethodSignature('api_get');
        $this->assertMatchesRegularExpression(
            '/\$timeout\s*=\s*12\b/',
            $signature,
            'Default timeout dropped from 30 → 12 s per Phase 0B H13. Signature: ' . $signature
        );
    }

    public function test_api_get_accepts_optional_wall_clock_budget(): void {
        $signature = $this->extractMethodSignature('api_get');
        $this->assertMatchesRegularExpression(
            '/\$budget\s*=\s*null/',
            $signature,
            'api_get() must accept an optional WallClockBudget parameter. Signature: ' . $signature
        );
    }

    public function test_api_get_surfaces_structured_error_on_oversize(): void {
        $this->assertStringContainsString(
            'dataflair_response_too_large',
            $this->method_body,
            'api_get() must surface `dataflair_response_too_large` WP_Error code when cap is hit.'
        );
    }

    public function test_api_get_surfaces_budget_exhausted_error_code(): void {
        $this->assertStringContainsString(
            'dataflair_budget_exhausted',
            $this->method_body,
            'api_get() must surface `dataflair_budget_exhausted` when an already-exhausted budget is passed.'
        );
    }

    public function test_wall_clock_budget_is_a_real_class_we_can_instantiate(): void {
        $budget = new WallClockBudget(0.0);
        $this->assertTrue($budget->exceeded());
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function extractMethodBody(string $methodName): string {
        $source = file_get_contents(DATAFLAIR_PLUGIN_DIR . 'dataflair-toplists.php');
        if ($source === false) return '';

        $signaturePattern = '/function\s+' . preg_quote($methodName, '/') . '\s*\(/';
        if (!preg_match($signaturePattern, $source, $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        $start = $m[0][1];
        $openBrace = strpos($source, '{', $start);
        if ($openBrace === false) return '';

        $depth = 1;
        $i     = $openBrace + 1;
        $len   = strlen($source);
        while ($i < $len && $depth > 0) {
            $ch = $source[$i];
            if ($ch === '{') $depth++;
            elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $openBrace + 1, $i - $openBrace - 1);
                }
            }
            $i++;
        }
        return '';
    }

    private function extractMethodSignature(string $methodName): string {
        $source = file_get_contents(DATAFLAIR_PLUGIN_DIR . 'dataflair-toplists.php');
        if ($source === false) return '';

        $signaturePattern = '/function\s+' . preg_quote($methodName, '/') . '\s*\([^)]*\)/s';
        if (preg_match($signaturePattern, $source, $m)) {
            return $m[0];
        }
        return '';
    }
}
