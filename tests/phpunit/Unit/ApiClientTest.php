<?php
/**
 * Phase 2 — behavioural pin for ApiClient.
 *
 * Uses Brain Monkey to stub wp_remote_get / wp_remote_retrieve_* so every
 * retry, timeout, size-cap, and budget short-circuit path is exercised in
 * isolation. This replaces the old structural scan of `api_get()` that
 * SyncApiSizeCapTest used — which is now retained as a source-level pin
 * against the new ApiClient class itself.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Http;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use DataFlair\Toplists\Http\ApiClient;
use DataFlair\Toplists\Logging\LoggerFactory;
use DataFlair\Toplists\Logging\NullLogger;
use DataFlair\Toplists\Support\WallClockBudget;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/NullLogger.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerFactory.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Support/WallClockBudget.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Http/HttpClientInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Http/ApiClient.php';
require_once DATAFLAIR_PLUGIN_DIR . 'tests/phpunit/WpErrorStub.php';

final class ApiClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        LoggerFactory::reset();

        Filters\expectApplied('dataflair_logger')
            ->andReturnUsing(static fn() => new NullLogger());
        Filters\expectApplied('dataflair_logger_level')
            ->andReturnUsing(static fn($default) => $default);

        Functions\when('get_option')->alias(static function ($key, $default = '') {
            return $default;
        });

        Functions\when('wp_remote_retrieve_body')->alias(static function ($response) {
            return is_array($response) && isset($response['body']) ? $response['body'] : '';
        });
        Functions\when('wp_remote_retrieve_response_code')->alias(static function ($response) {
            return is_array($response) && isset($response['response']['code'])
                ? (int) $response['response']['code']
                : 0;
        });
        Functions\when('is_wp_error')->alias(static function ($thing) {
            return $thing instanceof \WP_Error;
        });
    }

    protected function tearDown(): void
    {
        LoggerFactory::reset();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_budget_exhausted_before_request_short_circuits_without_http_call(): void
    {
        Functions\expect('wp_remote_get')->never();

        $client = new ApiClient(new NullLogger());
        $budget = new WallClockBudget(0.0);

        $result = $client->get('https://api.example.com/toplists', 'tok', 12, 2, $budget);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('dataflair_budget_exhausted', $result->get_error_code());
    }

    public function test_oversized_body_returns_structured_error_without_retry(): void
    {
        $huge_body = str_repeat('x', 15 * 1024 * 1024 + 128);

        Functions\expect('wp_remote_get')->once()->andReturn([
            'body'     => $huge_body,
            'response' => ['code' => 200],
        ]);

        $client = new ApiClient(new NullLogger());
        $result = $client->get('https://api.example.com/toplists', 'tok', 12, 2);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('dataflair_response_too_large', $result->get_error_code());
    }

    public function test_successful_2xx_returns_response_array(): void
    {
        Functions\expect('wp_remote_get')->once()->andReturn([
            'body'     => '{"ok":true}',
            'response' => ['code' => 200],
        ]);

        $client = new ApiClient(new NullLogger());
        $result = $client->get('https://api.example.com/toplists', 'tok');

        $this->assertIsArray($result);
        $this->assertSame('{"ok":true}', $result['body']);
    }

    public function test_wp_error_is_returned_after_exhausting_retries(): void
    {
        Functions\expect('wp_remote_get')
            ->times(3)
            ->andReturn(new \WP_Error('http_request_failed', 'connection refused'));

        Functions\when('sleep')->justReturn(0);

        $client = new ApiClient(new NullLogger());
        $result = $client->get('https://api.example.com/toplists', 'tok', 12, 2);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('http_request_failed', $result->get_error_code());
    }

    public function test_transient_5xx_retries_and_eventually_succeeds(): void
    {
        $call_count = 0;
        Functions\when('wp_remote_get')->alias(function () use (&$call_count) {
            $call_count++;
            if ($call_count === 1) {
                return ['body' => '', 'response' => ['code' => 503]];
            }
            return ['body' => '{"ok":true}', 'response' => ['code' => 200]];
        });
        Functions\when('sleep')->justReturn(0);

        $client = new ApiClient(new NullLogger());
        $result = $client->get('https://api.example.com/toplists', 'tok', 12, 2);

        $this->assertIsArray($result);
        $this->assertSame(200, $result['response']['code']);
        $this->assertSame(2, $call_count);
    }

    public function test_emits_dataflair_http_call_telemetry(): void
    {
        Functions\expect('wp_remote_get')->once()->andReturn([
            'body'     => '{"ok":true}',
            'response' => ['code' => 200],
        ]);

        $fired = [];
        Functions\when('do_action')->alias(function (...$args) use (&$fired) {
            if (($args[0] ?? null) === 'dataflair_http_call') {
                $fired[] = $args[1] ?? [];
            }
        });

        $client = new ApiClient(new NullLogger());
        $client->get('https://api.example.com/toplists', 'tok');

        $this->assertCount(1, $fired, 'dataflair_http_call must fire exactly once per request.');
        $this->assertSame(200, $fired[0]['status']);
        $this->assertArrayHasKey('elapsed_ms', $fired[0]);
        $this->assertArrayHasKey('bytes', $fired[0]);
    }

    public function test_implements_http_client_interface(): void
    {
        $client = new ApiClient(new NullLogger());
        $this->assertInstanceOf(\DataFlair\Toplists\Http\HttpClientInterface::class, $client);
    }
}
