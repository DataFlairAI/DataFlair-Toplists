<?php
/**
 * H3 regression test (Phase 2 retarget): scans `src/Http/LogoDownloader.php`
 * for the documented Phase 0B invariants now that `download_brand_logo()` is
 * a 1-line delegator. Behavioural assertions live in `LogoDownloaderTest`.
 *
 * Invariants asserted here:
 *   - LOGO_MAX_BYTES = 3 MB
 *   - LOGO_TIMEOUT = 8 s
 *   - HEAD request issued before GET
 *   - limit_response_size backstop passed into GET args
 *   - GET body >= cap returns false (downstream treated as failed download)
 *   - HEAD Content-Length > cap short-circuits
 */

use DataFlair\Toplists\Http\LogoDownloader;
use PHPUnit\Framework\TestCase;

class LogoSizeCapTest extends TestCase {

    private string $source = '';

    protected function setUp(): void {
        parent::setUp();
        $path = dirname(dirname(__DIR__)) . '/../src/Http/LogoDownloader.php';
        $resolved = realpath($path);
        $this->assertNotFalse($resolved, 'src/Http/LogoDownloader.php must exist relative to tests dir.');
        $contents = file_get_contents($resolved);
        $this->assertNotFalse($contents, 'Could not read LogoDownloader.php source.');
        $this->source = $contents;
    }

    public function test_logo_downloader_class_exists_and_loads(): void {
        $this->assertTrue(class_exists(LogoDownloader::class), 'LogoDownloader must autoload.');
    }

    public function test_logo_download_caps_bytes_at_exactly_3mb(): void {
        $this->assertMatchesRegularExpression(
            '/LOGO_MAX_BYTES\s*=\s*3\s*\*\s*1024\s*\*\s*1024\s*;/',
            $this->source,
            'Logo cap must be exactly 3 MB per Phase 0B H3 (3 * 1024 * 1024).'
        );
    }

    public function test_logo_download_uses_8_second_timeout(): void {
        $this->assertMatchesRegularExpression(
            '/LOGO_TIMEOUT\s*=\s*8\s*;/',
            $this->source,
            'Logo timeout must be 8 s per Phase 0B H3 (was 30 s).'
        );
    }

    public function test_logo_download_issues_head_before_get(): void {
        $head_pos = strpos($this->source, 'wp_remote_head(');
        $get_pos  = strpos($this->source, 'wp_remote_get(');

        $this->assertNotFalse($head_pos, 'LogoDownloader must issue a wp_remote_head() call.');
        $this->assertNotFalse($get_pos,  'LogoDownloader must still issue a wp_remote_get() call.');
        $this->assertLessThan(
            $get_pos,
            $head_pos,
            'HEAD must come before GET — it lets us short-circuit oversized downloads cheaply.'
        );
    }

    public function test_logo_download_passes_limit_response_size_into_get(): void {
        $this->assertMatchesRegularExpression(
            '/[\'"]limit_response_size[\'"]\s*=>\s*self::LOGO_MAX_BYTES/',
            $this->source,
            'Logo GET must pass limit_response_size => self::LOGO_MAX_BYTES as a backstop.'
        );
    }

    public function test_logo_download_returns_false_when_cap_hit_on_get(): void {
        $this->assertMatchesRegularExpression(
            '/strlen\(\(string\) \$image_data\)\s*>=\s*self::LOGO_MAX_BYTES/',
            $this->source,
            'Logo GET must treat cap-hit body as a failed download.'
        );
    }

    public function test_logo_download_bails_when_head_content_length_exceeds_cap(): void {
        $this->assertMatchesRegularExpression(
            '/\$content_length\s*>\s*self::LOGO_MAX_BYTES/',
            $this->source,
            'Logo HEAD branch must bail when Content-Length > cap.'
        );
    }

    public function test_logo_downloader_fires_brand_logo_stored_action(): void {
        $this->assertStringContainsString(
            "'dataflair_brand_logo_stored'",
            $this->source,
            'LogoDownloader must fire the Phase 0A dataflair_brand_logo_stored action hook.'
        );
    }
}
