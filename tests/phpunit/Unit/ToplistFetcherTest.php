<?php
/**
 * Phase 9.10 — Pins ToplistFetcher behaviour: WP_Error short-circuit,
 * non-200 short-circuit (calls error builder), JSON parse failure,
 * missing data.id, happy path delegation to ToplistDataStore.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Sync;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DataFlair\Toplists\Database\ToplistDataStore;
use DataFlair\Toplists\Http\HttpClientInterface;
use DataFlair\Toplists\Sync\ToplistFetcher;
use Mockery as M;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Http/HttpClientInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/ToplistDataStore.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/ToplistFetcher.php';
require_once DATAFLAIR_PLUGIN_DIR . 'tests/phpunit/WpErrorStub.php';

final class ToplistFetcherTest extends TestCase
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
        Functions\when('wp_remote_retrieve_headers')->alias(static function ($response) {
            return is_array($response) && isset($response['headers']) ? $response['headers'] : [];
        });
        Functions\when('add_settings_error')->justReturn(null);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        M::close();
        parent::tearDown();
    }

    private function neverErrorBuilder(): \Closure
    {
        return static function (int $status, string $body, $headers, string $endpoint): string {
            throw new \LogicException('errorBuilder must not be called on this path');
        };
    }

    public function test_returns_false_on_wp_error_without_calling_store(): void
    {
        $http = M::mock(HttpClientInterface::class);
        $http->shouldReceive('get')->once()->andReturn(new \WP_Error('http_error', 'boom'));

        $store = M::mock(ToplistDataStore::class);
        $store->shouldNotReceive('store');

        $fetcher = new ToplistFetcher($http, $store, $this->neverErrorBuilder());
        $this->assertFalse($fetcher->fetchAndStore('https://x/toplists/1', 'tok'));
    }

    public function test_returns_false_on_non_200_and_invokes_error_builder(): void
    {
        $http = M::mock(HttpClientInterface::class);
        $http->shouldReceive('get')->once()->andReturn([
            'response' => ['code' => 500],
            'body'     => 'server-error-body',
            'headers'  => ['x-foo' => 'bar'],
        ]);

        $store = M::mock(ToplistDataStore::class);
        $store->shouldNotReceive('store');

        $captured = [];
        $errorBuilder = static function (int $status, string $body, $headers, string $endpoint) use (&$captured): string {
            $captured = compact('status', 'body', 'endpoint');
            return 'formatted error message';
        };

        $fetcher = new ToplistFetcher($http, $store, $errorBuilder);
        $this->assertFalse($fetcher->fetchAndStore('https://x/toplists/9', 'tok'));

        $this->assertSame(500, $captured['status']);
        $this->assertSame('server-error-body', $captured['body']);
        $this->assertSame('https://x/toplists/9', $captured['endpoint']);
    }

    public function test_returns_false_on_invalid_json(): void
    {
        $http = M::mock(HttpClientInterface::class);
        $http->shouldReceive('get')->once()->andReturn([
            'response' => ['code' => 200],
            'body'     => 'not-json{',
            'headers'  => [],
        ]);

        $store = M::mock(ToplistDataStore::class);
        $store->shouldNotReceive('store');

        $fetcher = new ToplistFetcher($http, $store, $this->neverErrorBuilder());
        $this->assertFalse($fetcher->fetchAndStore('https://x/toplists/1', 'tok'));
    }

    public function test_returns_false_when_data_id_missing(): void
    {
        $http = M::mock(HttpClientInterface::class);
        $http->shouldReceive('get')->once()->andReturn([
            'response' => ['code' => 200],
            'body'     => json_encode(['data' => ['name' => 'no id here']]),
            'headers'  => [],
        ]);

        $store = M::mock(ToplistDataStore::class);
        $store->shouldNotReceive('store');

        $fetcher = new ToplistFetcher($http, $store, $this->neverErrorBuilder());
        $this->assertFalse($fetcher->fetchAndStore('https://x/toplists/1', 'tok'));
    }

    public function test_happy_path_delegates_payload_and_raw_body_to_store(): void
    {
        $payload = ['data' => ['id' => 42, 'name' => 'Top']];
        $body = json_encode($payload);

        $http = M::mock(HttpClientInterface::class);
        $http->shouldReceive('get')->once()->andReturn([
            'response' => ['code' => 200],
            'body'     => $body,
            'headers'  => [],
        ]);

        $store = M::mock(ToplistDataStore::class);
        $store->shouldReceive('store')
            ->once()
            ->with($payload['data'], $body)
            ->andReturn(true);

        $fetcher = new ToplistFetcher($http, $store, $this->neverErrorBuilder());
        $this->assertTrue($fetcher->fetchAndStore('https://x/toplists/42', 'tok'));
    }
}
