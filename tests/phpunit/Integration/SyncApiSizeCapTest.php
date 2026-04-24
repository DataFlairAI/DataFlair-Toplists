<?php
/**
 * H2 regression test (Phase 2 retarget): scans `src/Http/ApiClient.php` for
 * the documented Phase 0B invariants now that `api_get()` is a 1-line
 * delegator. The behavioural test lives alongside in `ApiClientTest`.
 *
 * Invariants asserted here:
 *   - 15 MB body cap passed to wp_remote_get as limit_response_size
 *   - 12 s default timeout (H13)
 *   - Optional WallClockBudget parameter
 *   - Structured `dataflair_response_too_large` error code
 *   - Structured `dataflair_budget_exhausted` error code
 *   - Existing WallClockBudget primitive is reachable from the autoloader.
 */

use DataFlair\Toplists\Http\ApiClient;
use DataFlair\Toplists\Support\WallClockBudget;
use PHPUnit\Framework\TestCase;

class SyncApiSizeCapTest extends TestCase {

    private string $source = '';

    protected function setUp(): void {
        parent::setUp();
        $path = dirname(dirname(__DIR__)) . '/../src/Http/ApiClient.php';
        $resolved = realpath($path);
        $this->assertNotFalse($resolved, 'src/Http/ApiClient.php must exist relative to tests dir.');
        $contents = file_get_contents($resolved);
        $this->assertNotFalse($contents, 'Could not read ApiClient.php source.');
        $this->source = $contents;
    }

    public function test_api_client_class_exists_and_loads(): void {
        $this->assertTrue(class_exists(ApiClient::class), 'ApiClient must autoload.');
    }

    public function test_api_client_passes_limit_response_size_to_wp_remote_get(): void {
        $this->assertMatchesRegularExpression(
            '/[\'"]limit_response_size[\'"]\s*=>\s*self::MAX_BYTES/',
            $this->source,
            'ApiClient must pass limit_response_size => self::MAX_BYTES into wp_remote_get args.'
        );
    }

    public function test_api_client_caps_bytes_at_exactly_15mb(): void {
        $this->assertMatchesRegularExpression(
            '/MAX_BYTES\s*=\s*15\s*\*\s*1024\s*\*\s*1024\s*;/',
            $this->source,
            'Cap must be exactly 15 MB per Phase 0B H2 (15 * 1024 * 1024).'
        );
    }

    public function test_api_client_default_timeout_is_12_seconds(): void {
        $this->assertMatchesRegularExpression(
            '/\$timeout\s*=\s*12\b/',
            $this->source,
            'Default timeout dropped from 30 → 12 s per Phase 0B H13.'
        );
    }

    public function test_api_client_accepts_optional_wall_clock_budget(): void {
        $this->assertMatchesRegularExpression(
            '/\?WallClockBudget\s+\$budget\s*=\s*null/',
            $this->source,
            'ApiClient::get() must accept an optional WallClockBudget parameter.'
        );
    }

    public function test_api_client_surfaces_structured_error_on_oversize(): void {
        $this->assertStringContainsString(
            'dataflair_response_too_large',
            $this->source,
            'ApiClient must surface `dataflair_response_too_large` WP_Error code when cap is hit.'
        );
    }

    public function test_api_client_surfaces_budget_exhausted_error_code(): void {
        $this->assertStringContainsString(
            'dataflair_budget_exhausted',
            $this->source,
            'ApiClient must surface `dataflair_budget_exhausted` when an already-exhausted budget is passed.'
        );
    }

    public function test_wall_clock_budget_is_a_real_class_we_can_instantiate(): void {
        $budget = new WallClockBudget(0.0);
        $this->assertTrue($budget->exceeded());
    }
}
