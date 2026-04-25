<?php
/**
 * Phase 9.6 (admin UX redesign) — pins ApiHealthHandler contract.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Admin\Ajax;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DataFlair\Toplists\Admin\Ajax\ApiHealthHandler;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/AjaxHandlerInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Ajax/ApiHealthHandler.php';

final class ApiHealthHandlerTest extends TestCase
{
    protected function setUp(): void    { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    public function test_returns_cached_result_when_transient_exists(): void
    {
        $cached = ['status' => 'healthy', 'ping_ms' => 42, 'error' => ''];
        Functions\when('get_transient')->justReturn($cached);

        $result = (new ApiHealthHandler())->handle([]);

        $this->assertTrue($result['success']);
        $this->assertSame('healthy', $result['data']['status']);
        $this->assertSame(42, $result['data']['ping_ms']);
    }

    public function test_returns_unconfigured_when_no_token(): void
    {
        Functions\when('get_transient')->justReturn(false);
        Functions\when('get_option')->alias(static fn($k, $d = false) => '');


        $result = (new ApiHealthHandler())->handle([]);

        $this->assertTrue($result['success']);
        $this->assertSame('unconfigured', $result['data']['status']);
    }

    public function test_returns_failing_on_wp_error(): void
    {
        Functions\when('get_transient')->justReturn(false);
        Functions\when('get_option')->alias(static fn($k, $d = false) => match ($k) {
            'dataflair_api_token'    => 'tok',
            'dataflair_api_base_url' => 'https://api.test',
            default => $d,
        });


        $err = \Mockery::mock(\WP_Error::class);
        $err->shouldReceive('get_error_message')->andReturn('cURL error');
        Functions\when('wp_remote_get')->justReturn($err);
        Functions\when('is_wp_error')->justReturn(true);

        $result = (new ApiHealthHandler())->handle([]);

        $this->assertTrue($result['success']);
        $this->assertSame('failing', $result['data']['status']);
        $this->assertStringContainsString('cURL', $result['data']['error']);
    }

    public function test_returns_healthy_on_200(): void
    {
        Functions\when('get_transient')->justReturn(false);
        Functions\when('get_option')->alias(static fn($k, $d = false) => match ($k) {
            'dataflair_api_token'    => 'tok',
            'dataflair_api_base_url' => 'https://api.test',
            default => $d,
        });

        Functions\when('wp_remote_get')->justReturn(['response' => ['code' => 200]]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);

        $result = (new ApiHealthHandler())->handle([]);

        $this->assertTrue($result['success']);
        $this->assertSame('healthy', $result['data']['status']);
    }
}
