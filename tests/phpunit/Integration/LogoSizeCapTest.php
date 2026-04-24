<?php
/**
 * H3 regression test: download_brand_logo() caps downloads at 3 MB with an
 * 8 s timeout and does a HEAD-before-GET for Content-Length.
 *
 * Sigma latent-OOM root cause: a brand with a misuploaded hero image
 * (~50 MB PNG) triggered both a network hang and, on older code paths,
 * a sideload chain that called wp_check_filetype_and_ext → finfo_file on
 * the resulting file, blowing the 1 GB ceiling.
 *
 * This is a structural scan of download_brand_logo() in the plugin file
 * (same pattern as SyncApiSizeCapTest). Execution-path coverage lands in
 * Phase 2 when LogoDownloader is extracted as a testable class.
 */

use PHPUnit\Framework\TestCase;

class LogoSizeCapTest extends TestCase {

    private string $method_body = '';

    protected function setUp(): void {
        parent::setUp();
        $this->method_body = $this->extractMethodBody('download_brand_logo');
        $this->assertNotEmpty(
            $this->method_body,
            'download_brand_logo() method body must be found in plugin file.'
        );
    }

    public function test_logo_download_caps_bytes_at_exactly_3mb(): void {
        $this->assertMatchesRegularExpression(
            '/\$logo_max_bytes\s*=\s*3\s*\*\s*1024\s*\*\s*1024\s*;/',
            $this->method_body,
            'Logo cap must be exactly 3 MB per Phase 0B H3 (3 * 1024 * 1024).'
        );
    }

    public function test_logo_download_uses_8_second_timeout(): void {
        $this->assertMatchesRegularExpression(
            '/\$logo_timeout\s*=\s*8\s*;/',
            $this->method_body,
            'Logo timeout must be 8 s per Phase 0B H3 (was 30 s).'
        );
    }

    public function test_logo_download_issues_head_before_get(): void {
        $head_pos = strpos($this->method_body, 'wp_remote_head(');
        $get_pos  = strpos($this->method_body, 'wp_remote_get(');

        $this->assertNotFalse($head_pos, 'download_brand_logo() must issue a wp_remote_head() call.');
        $this->assertNotFalse($get_pos,  'download_brand_logo() must still issue a wp_remote_get() call.');
        $this->assertLessThan(
            $get_pos,
            $head_pos,
            'HEAD must come before GET — it lets us short-circuit oversized downloads cheaply.'
        );
    }

    public function test_logo_download_passes_limit_response_size_into_get(): void {
        $this->assertMatchesRegularExpression(
            '/[\'"]limit_response_size[\'"]\s*=>\s*\$logo_max_bytes/',
            $this->method_body,
            'Logo GET must pass limit_response_size => $logo_max_bytes as a backstop.'
        );
    }

    public function test_logo_download_returns_false_when_cap_hit_on_get(): void {
        $this->assertMatchesRegularExpression(
            '/strlen\(\$image_data\)\s*>=\s*\$logo_max_bytes/',
            $this->method_body,
            'Logo GET must treat cap-hit body as a failed download.'
        );
    }

    public function test_logo_download_bails_when_head_content_length_exceeds_cap(): void {
        $this->assertMatchesRegularExpression(
            '/\$content_length\s*>\s*\$logo_max_bytes/',
            $this->method_body,
            'Logo HEAD branch must bail when Content-Length > cap.'
        );
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
}
