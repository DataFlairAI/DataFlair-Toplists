<?php
/**
 * Phase 3 — behavioural pin for BrandSyncService.
 *
 * Pins: the brand status filter, the missing-id error path, HTTP WP_Error →
 * failure, page-1 DELETE trigger, and the SyncResult shape AJAX callers
 * depend on.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Sync;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DataFlair\Toplists\Database\BrandsRepositoryInterface;
use DataFlair\Toplists\Http\BrandsApiUrlBuilderInterface;
use DataFlair\Toplists\Http\HttpClientInterface;
use DataFlair\Toplists\Http\LogoDownloaderInterface;
use DataFlair\Toplists\Logging\LoggerInterface;
use DataFlair\Toplists\Logging\NullLogger;
use DataFlair\Toplists\Sync\BrandSyncService;
use DataFlair\Toplists\Sync\ContractMismatch;
use DataFlair\Toplists\Sync\BrandSyncServiceInterface;
use DataFlair\Toplists\Sync\SyncRequest;
use DataFlair\Toplists\Sync\SyncResult;
use DataFlair\Toplists\Support\WallClockBudget;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/NullLogger.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Support/WallClockBudget.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Http/HttpClientInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Http/LogoDownloaderInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Http/BrandsApiUrlBuilderInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/BrandsRepositoryInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/SyncRequest.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/SyncResult.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/BrandSyncOutcome.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/BrandSyncServiceInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/ContractMismatch.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/BrandSyncService.php';
require_once DATAFLAIR_PLUGIN_DIR . 'tests/phpunit/WpErrorStub.php';
require_once __DIR__ . '/SyncFunctionStubs.php';

final class BrandSyncServiceTest extends TestCase
{
    private FakeBrandsRepo $brands;
    private FakeLogoDownloader $logoDownloader;
    private FakeHttpClient $http;
    private FakeBrandsApiUrlBuilder $urlBuilder;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        \SyncFunctionStubsStore::reset();

        $this->brands         = new FakeBrandsRepo();
        $this->logoDownloader = new FakeLogoDownloader();
        $this->http           = new FakeHttpClient();
        $this->urlBuilder     = new FakeBrandsApiUrlBuilder();

        // Stub WP globals + functions the service touches.
        $wpdb         = new FakeWpdb();
        $GLOBALS['wpdb'] = $wpdb;

        Functions\when('is_wp_error')->alias(static fn($x) => $x instanceof \WP_Error);
        Functions\when('wp_remote_retrieve_body')->alias(
            static fn($r) => is_array($r) ? ($r['body'] ?? '') : ''
        );
        Functions\when('wp_remote_retrieve_response_code')->alias(
            static fn($r) => is_array($r) ? (int) ($r['response']['code'] ?? 0) : 0
        );
        Functions\when('wp_remote_retrieve_headers')->alias(static fn($r) => []);
        Functions\when('do_action')->justReturn(null);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    public function test_implements_interface(): void
    {
        $svc = $this->makeService();
        $this->assertInstanceOf(BrandSyncServiceInterface::class, $svc);
    }

    public function test_setup_resets_the_shared_stub_store(): void
    {
        // Defensive: BrandSyncService doesn't currently call get_option/
        // update_option/*_transient, but this file shares SyncFunctionStubsStore
        // with ToplistSyncServiceTest — reset() must run here too so that
        // never becomes an accidental cross-test leak vector.
        $this->assertSame([], \SyncFunctionStubsStore::$transients);
        $this->assertSame([], \SyncFunctionStubsStore::$options);
    }

    public function test_http_wp_error_returns_failure_result(): void
    {
        $this->http->response = new \WP_Error('http_request_failed', 'timeout');

        $svc    = $this->makeService();
        $result = $svc->syncPage(SyncRequest::brands(2));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('timeout', $result->message);
        $this->assertStringContainsString('page 2', $result->message);
        $this->assertCount(0, $this->brands->upserts, 'No upserts when HTTP errors.');
    }

    public function test_non_200_status_returns_failure_via_error_builder(): void
    {
        $this->http->response = [
            'body'     => '{"error":"forbidden"}',
            'response' => ['code' => 403],
        ];

        $svc    = $this->makeService();
        $result = $svc->syncPage(SyncRequest::brands(1));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('403', $result->message);
    }

    public function test_active_brand_gets_upserted_with_full_row_shape(): void
    {
        $this->http->response = $this->mockBrandsApiResponse([
            $this->brandPayload(42, 'Betway', 'Active', ['US', 'CA'], [['id' => 1]]),
        ]);

        $svc    = $this->makeService();
        $result = $svc->syncPage(SyncRequest::brands(1));

        $this->assertTrue($result->success);
        $this->assertSame(1, $result->synced);
        $this->assertSame(0, $result->errors);
        $this->assertCount(1, $this->brands->upserts);

        $row = $this->brands->upserts[0];
        $this->assertSame(42, $row['api_brand_id']);
        $this->assertSame('Betway', $row['name']);
        $this->assertSame('Active', $row['status']);
        $this->assertSame('US, CA', $row['top_geos']);
        $this->assertSame(1, $row['offers_count']);
        $this->assertArrayHasKey('last_synced', $row);
        $this->assertArrayHasKey('data', $row);
    }

    public function test_non_active_brand_is_skipped(): void
    {
        $this->http->response = $this->mockBrandsApiResponse([
            $this->brandPayload(42, 'Active one', 'Active', ['US'], []),
            $this->brandPayload(43, 'Inactive one', 'Inactive', ['US'], []),
            $this->brandPayload(44, 'Paused one', 'Paused', ['US'], []),
        ]);

        $svc    = $this->makeService();
        $result = $svc->syncPage(SyncRequest::brands(1));

        $this->assertTrue($result->success);
        $this->assertSame(1, $result->synced);
        $this->assertCount(1, $this->brands->upserts);
        $this->assertSame(42, $this->brands->upserts[0]['api_brand_id']);
    }

    public function test_brand_missing_id_counts_as_error_and_continues_loop(): void
    {
        $withoutId = $this->brandPayload(999, 'Nope', 'Active', ['US'], []);
        unset($withoutId['id']);

        $this->http->response = $this->mockBrandsApiResponse([
            $withoutId,
            $this->brandPayload(42, 'Real brand', 'Active', ['US'], []),
        ]);

        $svc    = $this->makeService();
        $result = $svc->syncPage(SyncRequest::brands(1));

        $this->assertTrue($result->success);
        $this->assertSame(1, $result->synced);
        $this->assertSame(1, $result->errors);
    }

    public function test_page_one_triggers_brands_table_delete(): void
    {
        $this->http->response = $this->mockBrandsApiResponse([]);

        $svc = $this->makeService();
        $svc->syncPage(SyncRequest::brands(1));

        $this->assertNotEmpty($GLOBALS['wpdb']->deleteQueries);
        $this->assertStringContainsString('DELETE FROM wp_dataflair_brands', $GLOBALS['wpdb']->deleteQueries[0]);
    }

    public function test_page_greater_than_one_does_not_delete(): void
    {
        $this->http->response = $this->mockBrandsApiResponse([]);

        $svc = $this->makeService();
        $svc->syncPage(SyncRequest::brands(3));

        $deletes = array_filter(
            $GLOBALS['wpdb']->deleteQueries,
            static fn($q) => str_contains($q, 'DELETE FROM wp_dataflair_brands')
        );
        $this->assertEmpty($deletes);
    }

    // ── API Contract Safety: fail-safe wipe ordering + 409 handshake ─────────

    public function test_backend_failure_on_page1_leaves_brands_table_untouched(): void
    {
        $this->http->response = new \WP_Error('timeout', 'dead backend');

        $result = $this->makeService()->syncPage(SyncRequest::brands(1));

        $this->assertFalse($result->success);
        $deletes = array_filter(
            $GLOBALS['wpdb']->deleteQueries,
            static fn($q) => str_contains($q, 'DELETE FROM wp_dataflair_brands')
        );
        $this->assertEmpty($deletes, 'a failed page-1 fetch must never wipe the local brands table');
    }

    public function test_contract_mismatch_409_records_state_and_aborts(): void
    {
        $this->http->response = [
            'body'     => json_encode([
                'error_code'         => 'contract_mismatch',
                'message'            => 'Plugin 1.5.0 is below the minimum supported version.',
                'min_plugin_version' => '2.5.0',
            ]),
            'response' => ['code' => 409],
        ];

        $result = $this->makeService()->syncPage(SyncRequest::brands(1));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('2.5.0', $result->message);
        $deletes = array_filter(
            $GLOBALS['wpdb']->deleteQueries,
            static fn($q) => str_contains($q, 'DELETE FROM wp_dataflair_brands')
        );
        $this->assertEmpty($deletes);

        $state = \SyncFunctionStubsStore::$options[ContractMismatch::OPTION]['brands'] ?? null;
        $this->assertIsArray($state);
        $this->assertSame('brands', $state['source']);
    }

    public function test_completed_brands_sync_clears_only_brands_mismatch(): void
    {
        \SyncFunctionStubsStore::$options[ContractMismatch::OPTION] = [
            'toplists' => ['message' => 'v1 mismatch', 'min_plugin_version' => '', 'source' => 'toplists'],
            'brands'   => ['message' => 'brands mismatch', 'min_plugin_version' => '', 'source' => 'brands'],
        ];
        // Clearing requires rows actually stored: sync one real brand.
        $this->http->response = $this->mockBrandsApiResponse([
            $this->brandPayload(42, 'Betway', 'Active', ['US'], []),
        ]);

        $this->makeService()->syncPage(SyncRequest::brands(1));

        $state = \SyncFunctionStubsStore::$options[ContractMismatch::OPTION] ?? [];
        $this->assertArrayNotHasKey('brands', $state, 'a completed brands sync clears its own mismatch');
        $this->assertArrayHasKey('toplists', $state, 'a brands success must not hide a toplists mismatch');
    }

    public function test_retyped_data_key_fails_before_the_wipe(): void
    {
        $this->http->response = [
            'body'     => json_encode(['data' => 'maintenance', 'meta' => ['last_page' => 1]]),
            'response' => ['code' => 200],
        ];

        $result = $this->makeService()->syncPage(SyncRequest::brands(1));

        $this->assertFalse($result->success);
        $deletes = array_filter(
            $GLOBALS['wpdb']->deleteQueries,
            static fn($q) => str_contains($q, 'DELETE FROM wp_dataflair_brands')
        );
        $this->assertEmpty($deletes, 'retyped data must never reach the brands wipe');
    }

    public function test_empty_page1_against_populated_brands_table_refuses_the_wipe(): void
    {
        $GLOBALS['wpdb']->countReturn = 50; // site currently has brands
        $this->http->response = $this->mockBrandsApiResponse([]);

        $result = $this->makeService()->syncPage(SyncRequest::brands(1));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('safety stop', $result->message);
        $deletes = array_filter(
            $GLOBALS['wpdb']->deleteQueries,
            static fn($q) => str_contains($q, 'DELETE FROM wp_dataflair_brands')
        );
        $this->assertEmpty($deletes, 'an empty payload must never wipe a populated brands table');
        $this->assertArrayHasKey('brands', \SyncFunctionStubsStore::$options[ContractMismatch::OPTION] ?? []);
    }

    public function test_success_result_exposes_total_keys_for_ajax_payload(): void
    {
        $this->http->response = $this->mockBrandsApiResponse(
            [$this->brandPayload(42, 'Betway', 'Active', ['US'], [])],
            ['total' => 123, 'last_page' => 7]
        );
        $GLOBALS['wpdb']->countReturn = 50;

        $svc    = $this->makeService();
        $result = $svc->syncPage(SyncRequest::brands(2));
        $array  = $result->toArray();

        $this->assertTrue($result->success);
        $this->assertSame(7, $array['last_page']);
        $this->assertSame(50, $array['total_synced']);
        $this->assertSame(123, $array['total_brands']);
        $this->assertFalse($array['is_complete'], 'Page 2 of 7 cannot be complete.');
    }

    public function test_is_complete_true_when_final_page_fully_consumed(): void
    {
        $this->http->response = $this->mockBrandsApiResponse(
            [$this->brandPayload(42, 'Betway', 'Active', ['US'], [])],
            ['total' => 5, 'last_page' => 3]
        );

        $svc    = $this->makeService();
        $result = $svc->syncPage(SyncRequest::brands(3));

        $this->assertTrue($result->isComplete);
    }

    public function test_logo_downloader_is_invoked_for_active_brands_only(): void
    {
        $this->http->response = $this->mockBrandsApiResponse([
            $this->brandPayload(42, 'Active', 'Active', ['US'], []),
            $this->brandPayload(43, 'Inactive', 'Inactive', ['US'], []),
        ]);

        $svc = $this->makeService();
        $svc->syncPage(SyncRequest::brands(1));

        $this->assertSame([42], $this->logoDownloader->downloadedBrandIds);
    }

    // ── Selected-ids run ("re-sync selected") ─────────────────────────────

    public function test_selected_ids_run_does_not_delete_even_on_page_one(): void
    {
        $this->http->response = $this->mockBrandsApiResponse([
            $this->brandPayload(42, 'Betway', 'Active', ['US'], []),
        ]);

        $svc    = $this->makeService();
        $result = $svc->syncPage(SyncRequest::brandsByIds([42, 99], 1));

        $this->assertTrue($result->success);
        $deletes = array_filter(
            $GLOBALS['wpdb']->deleteQueries,
            static fn($q) => str_contains($q, 'DELETE FROM wp_dataflair_brands')
        );
        $this->assertEmpty($deletes, 'a selected-ids run must never wipe the local brands table, even on page 1');
    }

    public function test_selected_ids_are_forwarded_to_the_url_builder(): void
    {
        $this->http->response = $this->mockBrandsApiResponse([]);

        $this->makeService()->syncPage(SyncRequest::brandsByIds([7, 8, 9], 2, 10));

        $this->assertSame([2, 10, [7, 8, 9]], $this->urlBuilder->received);
    }

    public function test_full_sync_url_builder_call_still_omits_ids(): void
    {
        $this->http->response = $this->mockBrandsApiResponse([]);

        $this->makeService()->syncPage(SyncRequest::brands(1));

        $this->assertNull($this->urlBuilder->received[2], 'a full sync must not accidentally pass an ids filter');
    }

    public function test_empty_result_for_selected_ids_does_not_trigger_safety_stop(): void
    {
        $GLOBALS['wpdb']->countReturn = 50; // site has brands locally
        $this->http->response = $this->mockBrandsApiResponse([]);

        $svc    = $this->makeService();
        $result = $svc->syncPage(SyncRequest::brandsByIds([999], 1));

        $this->assertTrue($result->success, 'an empty result for hand-picked ids is not a backend regression');
    }

    // ── syncOne() — webhook sync slice's brand.status_changed/brand.updated handler ──
    //
    // Unlike syncPage()'s list endpoint (always active() scoped, so it can never
    // report an inactive/gone brand - the whole reason this method exists), the
    // single-brand endpoint reports current truth regardless of status. Always
    // re-fetching rather than trusting a webhook payload's own "from"/"to" field
    // is what makes a reordered or replayed delivery harmless.

    public function test_sync_one_active_brand_upserts_and_clears_disabled_flag(): void
    {
        $this->http->response = [
            'body'     => json_encode(['data' => $this->brandPayload(42, 'Active Brand', 'Active', ['UK'], [])]),
            'response' => ['code' => 200],
        ];

        $outcome = $this->makeService()->syncOne(42);

        $this->assertSame('active', $outcome->status);
        $this->assertCount(1, $this->brands->upserts);
        $this->assertSame(42, $this->brands->upserts[0]['api_brand_id']);
        $this->assertCount(1, $this->brands->disabledCalls);
        $this->assertSame([42], $this->brands->disabledCalls[0]['ids']);
        $this->assertFalse($this->brands->disabledCalls[0]['disabled']);
    }

    public function test_sync_one_fires_dataflair_brand_synced_with_the_upserted_row(): void
    {
        $this->http->response = [
            'body'     => json_encode(['data' => $this->brandPayload(42, 'Active Brand', 'Active', ['UK'], [])]),
            'response' => ['code' => 200],
        ];

        // setUp() stubs do_action() with a blanket justReturn(null) - Brain
        // Monkey's when()/expect() for the same function name don't compose
        // (confirmed empirically: an expect() layered on top is never
        // reached), so this overrides it with a capturing alias instead,
        // the same technique ApiBaseUrlDetectorTest uses for update_option.
        $fired = [];
        Functions\when('do_action')->alias(function (...$args) use (&$fired) {
            $fired[] = $args;

            return null;
        });

        $this->makeService()->syncOne(42);

        $this->assertCount(1, $fired, 'dataflair_brand_synced should fire exactly once');
        $this->assertSame('dataflair_brand_synced', $fired[0][0]);
        $this->assertSame(42, $fired[0][1]);
        $this->assertSame('Active Brand', $fired[0][2]['name']);
    }

    public function test_sync_one_404_does_not_fire_dataflair_brand_synced(): void
    {
        // No upsert on this path (see class docblock: "404 -> disable only,
        // nothing to upsert") - the hook must not fire over a row that was
        // never written.
        $this->http->response = [
            'body'     => '',
            'response' => ['code' => 404],
        ];

        $fired = [];
        Functions\when('do_action')->alias(function (...$args) use (&$fired) {
            $fired[] = $args;

            return null;
        });

        $this->makeService()->syncOne(999);

        $this->assertSame([], $fired);
    }

    public function test_sync_one_inactive_brand_upserts_fresh_data_and_sets_disabled_flag(): void
    {
        $this->http->response = [
            'body'     => json_encode(['data' => $this->brandPayload(42, 'Now Inactive Brand', 'Inactive', [], [])]),
            'response' => ['code' => 200],
        ];

        $outcome = $this->makeService()->syncOne(42);

        $this->assertSame('inactive', $outcome->status);
        // Still worth capturing current data even though it'll be hidden.
        $this->assertCount(1, $this->brands->upserts);
        $this->assertSame('Now Inactive Brand', $this->brands->upserts[0]['name']);
        $this->assertCount(1, $this->brands->disabledCalls);
        $this->assertSame([42], $this->brands->disabledCalls[0]['ids']);
        $this->assertTrue($this->brands->disabledCalls[0]['disabled']);
    }

    public function test_sync_one_404_disables_without_upserting_or_crashing(): void
    {
        $this->http->response = [
            'body'     => json_encode(['message' => 'Not found']),
            'response' => ['code' => 404],
        ];

        $outcome = $this->makeService()->syncOne(999);

        $this->assertSame('gone', $outcome->status);
        $this->assertCount(0, $this->brands->upserts, '404 has no brand data to upsert');
        $this->assertCount(1, $this->brands->disabledCalls);
        $this->assertSame([999], $this->brands->disabledCalls[0]['ids']);
        $this->assertTrue($this->brands->disabledCalls[0]['disabled']);
    }

    public function test_sync_one_http_error_fails_without_touching_the_repository(): void
    {
        $this->http->response = new \WP_Error('http_request_failed', 'timeout');

        $outcome = $this->makeService()->syncOne(42);

        $this->assertSame('failed', $outcome->status);
        $this->assertStringContainsString('timeout', $outcome->message);
        $this->assertCount(0, $this->brands->upserts);
        $this->assertCount(0, $this->brands->disabledCalls);
    }

    public function test_sync_one_uses_the_single_brand_url_not_the_list_url(): void
    {
        $this->http->response = [
            'body'     => json_encode(['data' => $this->brandPayload(42, 'Active Brand', 'Active', ['UK'], [])]),
            'response' => ['code' => 200],
        ];

        $this->makeService()->syncOne(42);

        $this->assertSame('https://api.example.com/brands/42', $this->http->lastUrl);
    }

    private function makeService(): BrandSyncService
    {
        $errorBuilder = static function (int $status, string $body, $h, string $url): string {
            return "API error ({$status}) for {$url}: " . substr($body, 0, 100);
        };

        return new BrandSyncService(
            $this->http,
            $this->logoDownloader,
            $this->brands,
            new NullLogger(),
            'test-token',
            $this->urlBuilder,
            $errorBuilder
        );
    }

    /**
     * @param array<int, array<string,mixed>> $brands
     * @param array<string,int>               $meta
     * @return array<string,mixed>
     */
    private function mockBrandsApiResponse(array $brands, array $meta = []): array
    {
        $payload = [
            'data' => $brands,
            'meta' => array_merge(['total' => count($brands), 'last_page' => 1], $meta),
        ];
        return [
            'body'     => json_encode($payload),
            'response' => ['code' => 200],
        ];
    }

    /**
     * @param string[] $geos
     * @param array<int, array<string,mixed>> $offers
     * @return array<string,mixed>
     */
    private function brandPayload(int $id, string $name, string $status, array $geos, array $offers): array
    {
        return [
            'id'          => $id,
            'name'        => $name,
            'slug'        => strtolower(str_replace(' ', '-', $name)),
            'brandStatus' => $status,
            'productTypes' => ['Casino'],
            'licenses'     => ['MGA'],
            'topGeos'      => ['countries' => $geos],
            'offers'       => $offers,
            'offersCount'  => count($offers),
        ];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// In-test fakes (keep assertion surface local to this file).

final class FakeBrandsRepo implements BrandsRepositoryInterface
{
    /** @var array<int,array<string,mixed>> */
    public array $upserts = [];

    public function findByApiBrandId(int $id): ?array { return null; }
    public function findBySlug(string $slug): ?array { return null; }
    public function findByName(string $name): ?array { return null; }
    public function findManyByApiBrandIds(array $ids): array { return []; }
    public function findReviewPostsByApiBrandIds(array $ids): array { return []; }

    public function upsert(array $row)
    {
        $this->upserts[] = $row;
        return count($this->upserts);
    }

    public function updateLocalLogoUrl(int $id, string $u): bool { return true; }
    public function updateCachedReviewPostId(int $id, int $p): bool { return true; }
    public function updateReviewUrlOverrideByApiBrandId(int $api_brand_id, ?string $url): bool { return true; }
    /** @var array<int,array{ids:int[],disabled:bool}> */
    public array $disabledCalls = [];

    public function setDisabledByApiBrandIds(array $api_brand_ids, bool $disabled): int
    {
        $this->disabledCalls[] = ['ids' => $api_brand_ids, 'disabled' => $disabled];
        return count($api_brand_ids);
    }
    public function findPaginated(\DataFlair\Toplists\Database\BrandsQuery $query): \DataFlair\Toplists\Database\BrandsPage
    {
        return new \DataFlair\Toplists\Database\BrandsPage([], 0, 1, 25);
    }
    public function findActiveByApiBrandIds(array $api_brand_ids): array { return []; }
    public function collectDistinctValuesForFilter(string $field): array { return []; }
}

final class FakeLogoDownloader implements LogoDownloaderInterface
{
    /** @var int[] */
    public array $downloadedBrandIds = [];

    public function download(array $brand_data, string $brand_slug)
    {
        if (isset($brand_data['id'])) {
            $this->downloadedBrandIds[] = (int) $brand_data['id'];
        }
        return false; // no local_logo_url on disk for tests
    }
}

final class FakeHttpClient implements HttpClientInterface
{
    public mixed $response = null;
    public ?string $lastUrl = null;

    public function get(string $url, string $token, int $timeout = 12, int $max_retries = 2, ?WallClockBudget $budget = null)
    {
        $this->lastUrl = $url;
        return $this->response;
    }

    public function post(string $url, string $token, array $body, int $timeout = 12)
    {
        $this->lastUrl = $url;
        return $this->response;
    }
}

final class FakeBrandsApiUrlBuilder implements BrandsApiUrlBuilderInterface
{
    /** @var array{0:int,1:int,2:?array}|null */
    public ?array $received = null;

    public function buildPageUrl(int $page, int $perPage = 25, ?array $ids = null): string
    {
        $this->received = [$page, $perPage, $ids];

        return 'https://api.example.com/brands?page=' . $page;
    }

    public function buildSingleUrl(int $apiBrandId): string
    {
        return 'https://api.example.com/brands/' . $apiBrandId;
    }
}

final class FakeWpdb
{
    public string $prefix = 'wp_';
    public int $countReturn = 0;
    /** @var string[] */
    public array $deleteQueries = [];

    public function query(string $sql): int
    {
        if (stripos($sql, 'DELETE FROM') === 0) {
            $this->deleteQueries[] = $sql;
            return 0; // end the pagination loop
        }
        return 0;
    }

    public function get_var(string $sql): int
    {
        return $this->countReturn;
    }
}
