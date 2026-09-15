<?php
/**
 * WebhookControllerTest — POST /wp-json/dataflair/v1/webhooks. The plugin's
 * first unauthenticated-but-signed route: permission_callback is
 * '__return_true' in RestRouter, all auth happens here via HMAC instead of
 * a WP capability check (the caller is DataFlair's queue worker).
 *
 * Order matters and is pinned by these tests: signature -> timestamp ->
 * idempotency -> tenant guard -> route to handler.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Rest\Controllers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DataFlair\Toplists\Http\ApiBaseUrlDetector;
use DataFlair\Toplists\Logging\NullLogger;
use DataFlair\Toplists\Rest\Controllers\WebhookController;
use DataFlair\Toplists\Sync\BrandSyncOutcome;
use DataFlair\Toplists\Sync\BrandSyncServiceInterface;
use DataFlair\Toplists\Sync\SyncRequest;
use DataFlair\Toplists\Sync\SyncResult;
use DataFlair\Toplists\Sync\ToplistPersisterInterface;
use DataFlair\Toplists\Webhooks\WebhookEventsRepositoryInterface;
use DataFlair\Toplists\Webhooks\WebhookSignatureVerifier;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/NullLogger.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/SyncRequest.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/SyncResult.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/BrandSyncOutcome.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/BrandSyncServiceInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/ToplistPersisterInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Webhooks/WebhookEventsRepositoryInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Webhooks/WebhookSignatureVerifierInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Webhooks/WebhookSignatureVerifier.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Support/UrlValidator.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Support/UrlTransformer.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Http/ApiBaseUrlDetector.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Rest/Controllers/WebhookController.php';
require_once __DIR__ . '/RestControllerTestStubs.php';
require_once __DIR__ . '/SyncFunctionStubs.php';

final class WebhookControllerTest extends TestCase
{
    private const SECRET = 'shared-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        // ApiBaseUrlDetector lives in the Http namespace, which has no
        // namespace-local get_option() stub (unlike Rest\Controllers below) -
        // Brain Monkey/Patchwork intercepts it instead, same as
        // BrandsApiUrlBuilderTest does for the same class. Backed by the same
        // SyncFunctionStubsStore so both interception paths agree.
        Functions\when('get_option')->alias(
            fn ($key, $default = false) => \SyncFunctionStubsStore::$options[$key] ?? $default
        );
        \SyncFunctionStubsStore::reset();
        \SyncFunctionStubsStore::$options['dataflair_webhook_secret']  = self::SECRET;
        \SyncFunctionStubsStore::$options['dataflair_api_base_url']    = 'https://tenant.dataflair.ai/api/v1';
        \SyncFunctionStubsStore::$options['dataflair_webhook_enabled'] = '1';
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function signedRequest(array $payload, ?string $secret = null): \WP_REST_Request
    {
        $body = json_encode($payload);
        $request = new \WP_REST_Request();
        $request->set_body($body);
        $request->set_header('X-DataFlair-Signature', hash_hmac('sha256', $body, $secret ?? self::SECRET));
        $request->set_header('X-DataFlair-Event', (string) ($payload['event'] ?? ''));
        $request->set_header('X-DataFlair-Delivery', (string) ($payload['delivery_id'] ?? ''));

        return $request;
    }

    private function toplistPublishedPayload(array $overrides = []): array
    {
        return array_merge([
            'event'       => 'toplist.published',
            'delivery_id' => 'delivery-1',
            'occurred_at' => gmdate('c'),
            'tenant_host' => 'tenant.dataflair.ai',
            'data'        => ['toplist_id' => 42],
        ], $overrides);
    }

    private function controller(
        ?FakeToplistPersister $toplistPersister = null,
        ?SpyBrandSyncService $brandSync = null,
        ?FakeWebhookEventsRepo $events = null,
        ?SpyLoggerForWebhookController $logger = null
    ): array {
        $toplistPersister = $toplistPersister ?? new FakeToplistPersister();
        $brandSync        = $brandSync ?? new SpyBrandSyncService();
        $events           = $events ?? new FakeWebhookEventsRepo();
        $logger           = $logger ?? new SpyLoggerForWebhookController();

        $controller = new WebhookController(
            new WebhookSignatureVerifier(),
            $events,
            $toplistPersister,
            $brandSync,
            new ApiBaseUrlDetector(new \DataFlair\Toplists\Support\UrlTransformer(new \DataFlair\Toplists\Support\UrlValidator())),
            'plugin-api-token',
            $logger
        );

        return [$controller, $toplistPersister, $brandSync, $events, $logger];
    }

    public function test_rejects_an_invalid_signature(): void
    {
        [$controller] = $this->controller();
        $request = $this->signedRequest($this->toplistPublishedPayload(), 'wrong-secret');

        $response = $controller->receive($request);

        $this->assertSame(401, $response->get_status());
        $this->assertSame('invalid_signature', $response->get_data()['error']);
    }

    public function test_rejects_when_no_secret_is_configured(): void
    {
        \SyncFunctionStubsStore::$options['dataflair_webhook_secret'] = '';
        [$controller] = $this->controller();
        $request = $this->signedRequest($this->toplistPublishedPayload());

        $response = $controller->receive($request);

        $this->assertSame(401, $response->get_status());
    }

    public function test_rejects_a_stale_timestamp(): void
    {
        [$controller] = $this->controller();
        $stale = gmdate('c', time() - 600); // 10 minutes old
        $payload = $this->toplistPublishedPayload(['occurred_at' => $stale]);
        $request = $this->signedRequest($payload);

        $response = $controller->receive($request);

        $this->assertSame(401, $response->get_status());
        $this->assertSame('stale_timestamp', $response->get_data()['error']);
    }

    public function test_accepts_a_timestamp_within_the_five_minute_window(): void
    {
        [$controller] = $this->controller();
        $recent = gmdate('c', time() - 200); // just over 3 minutes old
        $payload = $this->toplistPublishedPayload(['occurred_at' => $recent]);
        $request = $this->signedRequest($payload);

        $response = $controller->receive($request);

        $this->assertSame(200, $response->get_status());
    }

    public function test_a_forged_fresh_timestamp_header_cannot_rescue_a_stale_signed_payload(): void
    {
        // Proves the fix: freshness is checked against the signed body's
        // occurred_at, not the (unsigned) X-DataFlair-Timestamp header, so
        // forging a fresh header on a captured/stale signed payload can no
        // longer bypass the staleness rejection.
        [$controller] = $this->controller();
        $stalePayload = $this->toplistPublishedPayload(['occurred_at' => gmdate('c', time() - 600)]);
        $body = json_encode($stalePayload);
        $request = new \WP_REST_Request();
        $request->set_body($body);
        $request->set_header('X-DataFlair-Signature', hash_hmac('sha256', $body, self::SECRET));
        $request->set_header('X-DataFlair-Timestamp', gmdate('c'));

        $response = $controller->receive($request);

        $this->assertSame(401, $response->get_status());
        $this->assertSame('stale_timestamp', $response->get_data()['error']);
    }

    public function test_replayed_delivery_id_returns_200_and_does_no_work(): void
    {
        $events = new FakeWebhookEventsRepo();
        $events->processed = ['delivery-1' => true];
        [$controller, $toplistPersister, $brandSync] = $this->controller(null, null, $events);

        $response = $controller->receive($this->signedRequest($this->toplistPublishedPayload()));

        $this->assertSame(200, $response->get_status());
        $this->assertSame('already_processed', $response->get_data()['status']);
        $this->assertNull($toplistPersister->calledWith, 'a replayed delivery must not re-run the handler');
    }

    public function test_tenant_host_mismatch_is_rejected_and_does_no_work(): void
    {
        [$controller, $toplistPersister] = $this->controller();
        $payload = $this->toplistPublishedPayload(['tenant_host' => 'a-different-tenant.dataflair.ai']);

        $response = $controller->receive($this->signedRequest($payload));

        $this->assertSame(409, $response->get_status());
        $this->assertSame('tenant_mismatch', $response->get_data()['error']);
        $this->assertNull($toplistPersister->calledWith);
    }

    public function test_rejects_and_does_no_work_when_the_expected_host_cannot_be_determined(): void
    {
        // A schemeless/malformed dataflair_api_base_url (e.g. a settings-field
        // typo) makes ApiBaseUrlDetector::detect() return a value with no
        // parseable host. The tenant guard must fail closed here, not treat
        // "can't tell" as "assume it matches".
        \SyncFunctionStubsStore::$options['dataflair_api_base_url'] = 'not-a-url';
        [$controller, $toplistPersister] = $this->controller();

        $response = $controller->receive($this->signedRequest($this->toplistPublishedPayload()));

        $this->assertSame(409, $response->get_status());
        $this->assertSame('tenant_mismatch', $response->get_data()['error']);
        $this->assertNull($toplistPersister->calledWith);
    }

    public function test_toplist_published_calls_the_toplist_fetcher_with_the_id_endpoint(): void
    {
        [$controller, $toplistPersister] = $this->controller();

        $response = $controller->receive($this->signedRequest($this->toplistPublishedPayload(['data' => ['toplist_id' => 77]])));

        $this->assertSame(200, $response->get_status());
        $this->assertSame('https://tenant.dataflair.ai/api/v1/toplists/77', $toplistPersister->calledWith[0]);
        $this->assertSame('plugin-api-token', $toplistPersister->calledWith[1]);
    }

    public function test_brand_status_changed_calls_sync_one_with_the_brand_id(): void
    {
        [$controller, , $brandSync] = $this->controller();
        $payload = [
            'event'       => 'brand.status_changed',
            'delivery_id' => 'delivery-2',
            'occurred_at' => gmdate('c'),
            'tenant_host' => 'tenant.dataflair.ai',
            'data'        => ['brand_id' => 99],
        ];

        $response = $controller->receive($this->signedRequest($payload));

        $this->assertSame(200, $response->get_status());
        $this->assertSame(99, $brandSync->calledWith);
    }

    public function test_brand_updated_also_routes_to_sync_one(): void
    {
        [$controller, , $brandSync] = $this->controller();
        $payload = [
            'event'       => 'brand.updated',
            'delivery_id' => 'delivery-3',
            'occurred_at' => gmdate('c'),
            'tenant_host' => 'tenant.dataflair.ai',
            'data'        => ['brand_id' => 5],
        ];

        $controller->receive($this->signedRequest($payload));

        $this->assertSame(5, $brandSync->calledWith);
    }

    public function test_records_the_delivery_as_processed_after_a_successful_handle(): void
    {
        $events = new FakeWebhookEventsRepo();
        [$controller] = $this->controller(null, null, $events);

        $controller->receive($this->signedRequest($this->toplistPublishedPayload(['delivery_id' => 'delivery-9'])));

        $this->assertTrue($events->processed['delivery-9'] ?? false);
    }

    public function test_logs_an_error_when_the_idempotency_ledger_write_fails(): void
    {
        $events = new FakeWebhookEventsRepo();
        $events->recordResult = false;
        [$controller, , , , $logger] = $this->controller(null, null, $events);

        $response = $controller->receive($this->signedRequest($this->toplistPublishedPayload(['delivery_id' => 'delivery-10'])));

        // The event was still handled and the caller still sees success -
        // routeEvent() already ran, and the handlers are idempotent, so a
        // future duplicate delivery is wasted work, not a correctness bug.
        // Only the silent-failure part is what this test pins.
        $this->assertSame(200, $response->get_status());
        $this->assertTrue($logger->hasLoggedContaining('delivery-10'), 'a failed ledger write must be logged, not swallowed');
    }

    public function test_malformed_payload_is_rejected(): void
    {
        [$controller] = $this->controller();
        $request = new \WP_REST_Request();
        $request->set_body('not json');
        $request->set_header('X-DataFlair-Signature', hash_hmac('sha256', 'not json', self::SECRET));

        $response = $controller->receive($request);

        $this->assertSame(400, $response->get_status());
    }

    public function test_rejects_and_does_no_work_when_webhook_sync_is_disabled(): void
    {
        // The admin unchecked "Enable webhook sync" - proves receive() itself
        // honors that, not just WebhookSelfRegistrar refusing to subscribe.
        \SyncFunctionStubsStore::$options['dataflair_webhook_enabled'] = '0';
        [$controller, $toplistPersister] = $this->controller();

        $response = $controller->receive($this->signedRequest($this->toplistPublishedPayload()));

        $this->assertSame(403, $response->get_status());
        $this->assertSame('webhook_disabled', $response->get_data()['error']);
        $this->assertNull($toplistPersister->calledWith);
    }

    public function test_rejects_and_does_no_work_when_nothing_is_configured_at_all(): void
    {
        // Distinct from test_rejects_and_does_no_work_when_the_expected_host_cannot_be_determined:
        // that one covers a malformed-but-non-empty base URL. This covers a
        // genuinely empty one, which is exactly the case detect() itself
        // can't signal (it falls back to a real, parseable DataFlair host
        // instead of returning empty) - only isConfigured() catches it.
        \SyncFunctionStubsStore::$options['dataflair_api_base_url'] = '';
        [$controller, $toplistPersister] = $this->controller();

        $response = $controller->receive($this->signedRequest($this->toplistPublishedPayload()));

        $this->assertSame(409, $response->get_status());
        $this->assertSame('tenant_mismatch', $response->get_data()['error']);
        $this->assertNull($toplistPersister->calledWith);
    }
}

final class FakeToplistPersister implements ToplistPersisterInterface
{
    /** @var array{0:string,1:string}|null */
    public ?array $calledWith = null;
    public bool $result = true;

    public function store(array $toplist, string $rawJson): bool
    {
        return $this->result;
    }

    public function fetchAndStore(string $endpoint, string $token): bool
    {
        $this->calledWith = [$endpoint, $token];

        return $this->result;
    }
}

final class SpyBrandSyncService implements BrandSyncServiceInterface
{
    public ?int $calledWith = null;

    public function syncPage(SyncRequest $request): SyncResult
    {
        return SyncResult::success($request->page, $request->page, 0, 0, false, true);
    }

    public function syncOne(int $apiBrandId): BrandSyncOutcome
    {
        $this->calledWith = $apiBrandId;

        return BrandSyncOutcome::active($apiBrandId);
    }
}

final class FakeWebhookEventsRepo implements WebhookEventsRepositoryInterface
{
    /** @var array<string,bool> */
    public array $processed = [];

    /** Simulates a ledger write failure (e.g. a $wpdb->insert() error). */
    public bool $recordResult = true;

    public function hasProcessed(string $deliveryId): bool
    {
        return $this->processed[$deliveryId] ?? false;
    }

    public function recordProcessed(string $deliveryId, string $eventType): bool
    {
        if (!$this->recordResult) {
            return false;
        }

        $this->processed[$deliveryId] = true;

        return true;
    }
}

final class SpyLoggerForWebhookController implements \DataFlair\Toplists\Logging\LoggerInterface
{
    /** @var list<array{level:string,message:string,context:array}> */
    public array $calls = [];

    public function emergency(string $message, array $context = []): void
    {
        $this->calls[] = ['level' => 'emergency', 'message' => $message, 'context' => $context];
    }

    public function alert(string $message, array $context = []): void
    {
        $this->calls[] = ['level' => 'alert', 'message' => $message, 'context' => $context];
    }

    public function critical(string $message, array $context = []): void
    {
        $this->calls[] = ['level' => 'critical', 'message' => $message, 'context' => $context];
    }

    public function error(string $message, array $context = []): void
    {
        $this->calls[] = ['level' => 'error', 'message' => $message, 'context' => $context];
    }

    public function warning(string $message, array $context = []): void
    {
        $this->calls[] = ['level' => 'warning', 'message' => $message, 'context' => $context];
    }

    public function notice(string $message, array $context = []): void
    {
        $this->calls[] = ['level' => 'notice', 'message' => $message, 'context' => $context];
    }

    public function info(string $message, array $context = []): void
    {
        $this->calls[] = ['level' => 'info', 'message' => $message, 'context' => $context];
    }

    public function debug(string $message, array $context = []): void
    {
        $this->calls[] = ['level' => 'debug', 'message' => $message, 'context' => $context];
    }

    public function hasLoggedContaining(string $needle): bool
    {
        foreach ($this->calls as $call) {
            if (str_contains($call['message'], $needle)) {
                return true;
            }
        }

        return false;
    }
}
