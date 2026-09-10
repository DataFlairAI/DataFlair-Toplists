<?php
/**
 * Phase 9.6 (admin UX redesign) — pins BulkResyncBrandsHandler contract.
 *
 * Verifies: rejects missing token, rejects an unconfigured API base URL
 * (the fallback-host credential-leak gap found in the second max-review
 * pass of v2.3.3 — a token was still sent to the hard-coded fallback host
 * even when Settings claimed there was "nothing to call"), rejects empty
 * IDs, and returns start_batch:true for a valid request.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Admin\Ajax;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DataFlair\Toplists\Admin\Ajax\BulkResyncBrandsHandler;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/AjaxHandlerInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Ajax/BulkResyncBrandsHandler.php';

final class BulkResyncBrandsHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function handler(bool $isConfigured = true): BulkResyncBrandsHandler
    {
        return new BulkResyncBrandsHandler(static fn (): bool => $isConfigured);
    }

    public function test_rejects_missing_api_token(): void
    {
        Functions\when('get_option')->alias(static fn($key, $default = false) => '');

        $result = $this->handler()->handle(['api_brand_ids' => [1, 2]]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('token', strtolower($result['data']['message']));
    }

    public function test_rejects_when_api_base_url_is_not_configured(): void
    {
        Functions\when('get_option')->alias(static fn($key, $default = false) => 'test-token');

        $result = $this->handler(isConfigured: false)->handle(['api_brand_ids' => [1, 2]]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('API Base URL is not configured', $result['data']['message']);
    }

    public function test_rejects_empty_brand_ids(): void
    {
        Functions\when('get_option')->alias(static fn($key, $default = false) => 'test-token');

        $result = $this->handler()->handle(['api_brand_ids' => []]);

        $this->assertFalse($result['success']);
    }

    public function test_returns_start_batch_true_for_valid_request(): void
    {
        Functions\when('get_option')->alias(static fn($key, $default = false) => 'test-token');

        $result = $this->handler()->handle(['api_brand_ids' => [1, 2, 3]]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['start_batch']);
    }

    public function test_response_message_includes_brand_count(): void
    {
        Functions\when('get_option')->alias(static fn($key, $default = false) => 'test-token');

        $result = $this->handler()->handle(['api_brand_ids' => [10, 20]]);

        $this->assertStringContainsString('2', $result['data']['message']);
    }
}
