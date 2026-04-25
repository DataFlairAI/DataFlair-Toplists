<?php
/**
 * Phase 9.10 — Pins EndpointDiscovery behaviour: pagination via
 * `meta.last_page`, graceful error short-circuit, ID extraction.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Sync;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DataFlair\Toplists\Http\HttpClientInterface;
use DataFlair\Toplists\Sync\EndpointDiscovery;
use Mockery as M;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Http/HttpClientInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/EndpointDiscovery.php';
require_once DATAFLAIR_PLUGIN_DIR . 'tests/phpunit/WpErrorStub.php';

final class EndpointDiscoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('is_wp_error')->alias(static function ($thing) {
            return $thing instanceof \WP_Error;
        });
        Functions\when('wp_remote_retrieve_response_code')->alias(static function ($response) {
            return is_array($response) && isset($response['response']['code'])
                ? (int) $response['response']['code']
                : 0;
        });
        Functions\when('wp_remote_retrieve_body')->alias(static function ($response) {
            return is_array($response) && isset($response['body']) ? $response['body'] : '';
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        M::close();
        parent::tearDown();
    }

    private function resolver(): \Closure
    {
        return static fn(): string => 'https://api.example.com/api/v2';
    }

    public function test_returns_empty_on_wp_error(): void
    {
        $http = M::mock(HttpClientInterface::class);
        $http->shouldReceive('get')->once()->andReturn(new \WP_Error('http_error', 'boom'));

        $discovery = new EndpointDiscovery($http, $this->resolver());
        $this->assertSame([], $discovery->discover('tok'));
    }

    public function test_returns_empty_on_non_200_status(): void
    {
        $http = M::mock(HttpClientInterface::class);
        $http->shouldReceive('get')->once()->andReturn([
            'response' => ['code' => 500],
            'body'     => '',
        ]);

        $discovery = new EndpointDiscovery($http, $this->resolver());
        $this->assertSame([], $discovery->discover('tok'));
    }

    public function test_returns_empty_on_invalid_json(): void
    {
        $http = M::mock(HttpClientInterface::class);
        $http->shouldReceive('get')->once()->andReturn([
            'response' => ['code' => 200],
            'body'     => 'not-json{',
        ]);

        $discovery = new EndpointDiscovery($http, $this->resolver());
        $this->assertSame([], $discovery->discover('tok'));
    }

    public function test_single_page_returns_endpoints_for_each_id(): void
    {
        $http = M::mock(HttpClientInterface::class);
        $http->shouldReceive('get')->once()->andReturn([
            'response' => ['code' => 200],
            'body'     => json_encode([
                'data' => [
                    ['id' => 11],
                    ['id' => 22],
                    ['no_id' => 'skipped'],
                ],
                'meta' => ['last_page' => 1],
            ]),
        ]);

        $discovery = new EndpointDiscovery($http, $this->resolver());

        $this->assertSame(
            [
                'https://api.example.com/api/v2/toplists/11',
                'https://api.example.com/api/v2/toplists/22',
            ],
            $discovery->discover('tok')
        );
    }

    public function test_walks_all_pages_until_last_page(): void
    {
        $http = M::mock(HttpClientInterface::class);
        $http->shouldReceive('get')
            ->times(3)
            ->andReturn(
                [
                    'response' => ['code' => 200],
                    'body'     => json_encode(['data' => [['id' => 1]], 'meta' => ['last_page' => 3]]),
                ],
                [
                    'response' => ['code' => 200],
                    'body'     => json_encode(['data' => [['id' => 2]], 'meta' => ['last_page' => 3]]),
                ],
                [
                    'response' => ['code' => 200],
                    'body'     => json_encode(['data' => [['id' => 3]], 'meta' => ['last_page' => 3]]),
                ]
            );

        $discovery = new EndpointDiscovery($http, $this->resolver());

        $this->assertSame(
            [
                'https://api.example.com/api/v2/toplists/1',
                'https://api.example.com/api/v2/toplists/2',
                'https://api.example.com/api/v2/toplists/3',
            ],
            $discovery->discover('tok')
        );
    }
}
