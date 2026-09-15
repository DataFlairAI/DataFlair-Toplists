<?php
/**
 * WebhookSelfRegistrarTest — fires when the plugin's "Enable webhook sync"
 * checkbox is turned on. Generates the local secret once (never regenerated
 * on a later save) and calls POST /api/v1/webhooks/subscribe with it.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Webhooks;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DataFlair\Toplists\Http\ApiBaseUrlDetector;
use DataFlair\Toplists\Http\HttpClientInterface;
use DataFlair\Toplists\Support\WallClockBudget;
use DataFlair\Toplists\Webhooks\WebhookSelfRegistrar;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Support/UrlValidator.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Support/UrlTransformer.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Http/HttpClientInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Http/ApiBaseUrlDetector.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Webhooks/WebhookSelfRegistrar.php';
require_once DATAFLAIR_PLUGIN_DIR . 'tests/phpunit/WpErrorStub.php';
require_once __DIR__ . '/SyncFunctionStubs.php';

final class WebhookSelfRegistrarTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        // WebhookSelfRegistrar lives in the Webhooks namespace, which has no
        // namespace-local get_option()/update_option() stub - Brain Monkey
        // intercepts instead, same approach as WebhookControllerTest for the
        // Http namespace. Backed by the same SyncFunctionStubsStore other
        // webhook tests use.
        Functions\when('get_option')->alias(
            fn ($key, $default = false) => \SyncFunctionStubsStore::$options[$key] ?? $default
        );
        Functions\when('update_option')->alias(function ($key, $value) {
            \SyncFunctionStubsStore::$options[$key] = $value;
            return true;
        });
        Functions\when('is_wp_error')->alias(fn ($thing) => $thing instanceof \WP_Error);
        Functions\when('wp_remote_retrieve_response_code')->alias(
            fn ($response) => is_array($response) ? (int) ($response['response']['code'] ?? 0) : 0
        );
        \SyncFunctionStubsStore::reset();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function registrar(FakeHttpClientForRegistrar $http): WebhookSelfRegistrar
    {
        return new WebhookSelfRegistrar(
            $http,
            new ApiBaseUrlDetector(new \DataFlair\Toplists\Support\UrlTransformer(new \DataFlair\Toplists\Support\UrlValidator()))
        );
    }

    public function test_generates_and_persists_a_secret_on_first_registration(): void
    {
        \SyncFunctionStubsStore::$options['dataflair_api_base_url'] = 'https://tenant.dataflair.ai/api/v1';
        $http = new FakeHttpClientForRegistrar(['body' => '{"status":"registered"}', 'response' => ['code' => 200]]);

        $result = $this->registrar($http)->register('https://mysite.example/wp-json/dataflair/v1/webhooks');

        $this->assertTrue($result);
        $secret = \SyncFunctionStubsStore::$options['dataflair_webhook_secret'] ?? '';
        $this->assertNotSame('', $secret);
        $this->assertGreaterThanOrEqual(32, strlen($secret));
    }

    public function test_does_not_regenerate_an_existing_secret(): void
    {
        \SyncFunctionStubsStore::$options['dataflair_api_base_url']    = 'https://tenant.dataflair.ai/api/v1';
        \SyncFunctionStubsStore::$options['dataflair_webhook_secret'] = 'already-set-secret';
        $http = new FakeHttpClientForRegistrar(['body' => '{"status":"registered"}', 'response' => ['code' => 200]]);

        $this->registrar($http)->register('https://mysite.example/wp-json/dataflair/v1/webhooks');

        $this->assertSame('already-set-secret', \SyncFunctionStubsStore::$options['dataflair_webhook_secret']);
        $this->assertSame('already-set-secret', $http->lastBody['secret'] ?? null);
    }

    public function test_calls_the_subscribe_endpoint_with_the_receiver_url_and_secret(): void
    {
        \SyncFunctionStubsStore::$options['dataflair_api_base_url'] = 'https://tenant.dataflair.ai/api/v1';
        \SyncFunctionStubsStore::$options['dataflair_api_token'] = 'plugin-api-token';
        $http = new FakeHttpClientForRegistrar(['body' => '{"status":"registered"}', 'response' => ['code' => 200]]);

        $this->registrar($http)->register('https://mysite.example/wp-json/dataflair/v1/webhooks');

        $this->assertSame('https://tenant.dataflair.ai/api/v1/webhooks/subscribe', $http->lastUrl);
        $this->assertSame('plugin-api-token', $http->lastToken);
        $this->assertSame('https://mysite.example/wp-json/dataflair/v1/webhooks', $http->lastBody['url'] ?? null);
    }

    public function test_uses_the_token_current_at_register_time_not_construction_time(): void
    {
        // Regression test: the token used to be a constructor property,
        // captured once at plugin-boot time (AdminBootstrap::boot(), which
        // runs before every request). SaveSettingsHandler saves a new token
        // and calls register() in the very same request - a captured value
        // would silently authenticate with the token this save just
        // replaced. register() must read the option fresh.
        \SyncFunctionStubsStore::$options['dataflair_api_base_url'] = 'https://tenant.dataflair.ai/api/v1';
        \SyncFunctionStubsStore::$options['dataflair_api_token'] = 'stale-token-from-boot';
        $http = new FakeHttpClientForRegistrar(['body' => '{"status":"registered"}', 'response' => ['code' => 200]]);
        $registrar = $this->registrar($http);

        // Simulates SaveSettingsHandler updating the token earlier in the
        // same request, after $registrar was already constructed.
        \SyncFunctionStubsStore::$options['dataflair_api_token'] = 'fresh-token-from-this-save';
        $registrar->register('https://mysite.example/wp-json/dataflair/v1/webhooks');

        $this->assertSame('fresh-token-from-this-save', $http->lastToken);
    }

    public function test_returns_false_on_a_non_200_response(): void
    {
        \SyncFunctionStubsStore::$options['dataflair_api_base_url'] = 'https://tenant.dataflair.ai/api/v1';
        $http = new FakeHttpClientForRegistrar(['body' => '{"message":"invalid"}', 'response' => ['code' => 422]]);

        $result = $this->registrar($http)->register('https://mysite.example/wp-json/dataflair/v1/webhooks');

        $this->assertFalse($result);
    }

    public function test_returns_false_on_a_transport_error(): void
    {
        \SyncFunctionStubsStore::$options['dataflair_api_base_url'] = 'https://tenant.dataflair.ai/api/v1';
        $http = new FakeHttpClientForRegistrar(new \WP_Error('http_request_failed', 'timeout'));

        $result = $this->registrar($http)->register('https://mysite.example/wp-json/dataflair/v1/webhooks');

        $this->assertFalse($result);
    }

    public function test_refuses_to_register_when_no_api_base_url_is_configured(): void
    {
        // Regression test: register() used to call detect(false) directly
        // with no configured-check, so an unconfigured site would silently
        // subscribe against the hard-coded fallback host (a real DataFlair
        // production host) instead of refusing outright.
        $http = new FakeHttpClientForRegistrar(['body' => '{"status":"registered"}', 'response' => ['code' => 200]]);

        $result = $this->registrar($http)->register('https://mysite.example/wp-json/dataflair/v1/webhooks');

        $this->assertFalse($result);
        $this->assertNull($http->lastUrl, 'must not call out to any host, fallback included, when unconfigured');
    }
}

final class FakeHttpClientForRegistrar implements HttpClientInterface
{
    public ?string $lastUrl = null;
    public ?string $lastToken = null;
    /** @var array<string,mixed>|null */
    public ?array $lastBody = null;

    public function __construct(private mixed $response)
    {
    }

    public function get(string $url, string $token, int $timeout = 12, int $max_retries = 2, ?WallClockBudget $budget = null)
    {
        return $this->response;
    }

    public function post(string $url, string $token, array $body, int $timeout = 12)
    {
        $this->lastUrl   = $url;
        $this->lastToken = $token;
        $this->lastBody  = $body;

        return $this->response;
    }
}
