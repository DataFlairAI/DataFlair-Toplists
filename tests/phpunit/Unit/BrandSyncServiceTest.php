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
use DataFlair\Toplists\Http\HttpClientInterface;
use DataFlair\Toplists\Http\LogoDownloaderInterface;
use DataFlair\Toplists\Logging\LoggerInterface;
use DataFlair\Toplists\Logging\NullLogger;
use DataFlair\Toplists\Sync\BrandSyncService;
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
require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/BrandsRepositoryInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/SyncRequest.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/SyncResult.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/BrandSyncServiceInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/BrandSyncService.php';
require_once DATAFLAIR_PLUGIN_DIR . 'tests/phpunit/WpErrorStub.php';
require_once __DIR__ . '/SyncFunctionStubs.php';

final class BrandSyncServiceTest extends TestCase
{
    private FakeBrandsRepo $brands;
    private FakeLogoDownloader $logoDownloader;
    private FakeHttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->brands         = new FakeBrandsRepo();
        $this->logoDownloader = new FakeLogoDownloader();
        $this->http           = new FakeHttpClient();

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
            static fn(int $page): string => 'https://api.example.com/brands?page=' . $page,
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
    public function findManyByApiBrandIds(array $ids): array { return []; }
    public function findReviewPostsByApiBrandIds(array $ids): array { return []; }

    public function upsert(array $row)
    {
        $this->upserts[] = $row;
        return count($this->upserts);
    }

    public function updateLocalLogoUrl(int $id, string $u): bool { return true; }
    public function updateCachedReviewPostId(int $id, int $p): bool { return true; }
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

    public function get(string $url, string $token, int $timeout = 12, int $max_retries = 2, ?WallClockBudget $budget = null)
    {
        return $this->response;
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
