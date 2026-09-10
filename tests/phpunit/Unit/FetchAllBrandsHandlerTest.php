<?php
/**
 * Pins FetchAllBrandsHandler's guards: rejects a missing token, and rejects
 * an unconfigured API base URL rather than letting the batch sync it kicks
 * off fall through to ApiBaseUrlDetector's hard-coded fallback host with a
 * real bearer token (found in the second max-review pass of v2.3.3).
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Admin\Ajax;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DataFlair\Toplists\Admin\Ajax\FetchAllBrandsHandler;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/AjaxHandlerInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Ajax/FetchAllBrandsHandler.php';

final class FetchAllBrandsHandlerTest extends TestCase
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

    private function handler(bool $isConfigured = true): FetchAllBrandsHandler
    {
        return new FetchAllBrandsHandler(static fn (): bool => $isConfigured);
    }

    public function test_rejects_missing_api_token(): void
    {
        Functions\when('get_option')->alias(static fn($key, $default = false) => '');

        $result = $this->handler()->handle([]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('token', strtolower($result['data']['message']));
    }

    public function test_rejects_when_api_base_url_is_not_configured(): void
    {
        Functions\when('get_option')->alias(static fn($key, $default = false) => 'test-token');

        $result = $this->handler(isConfigured: false)->handle([]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('API Base URL is not configured', $result['data']['message']);
    }

    public function test_returns_start_batch_true_when_token_and_base_url_are_set(): void
    {
        Functions\when('get_option')->alias(static fn($key, $default = false) => 'test-token');

        $result = $this->handler()->handle([]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['start_batch']);
    }
}
