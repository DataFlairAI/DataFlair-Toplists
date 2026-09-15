<?php
/**
 * Pins SyncBrandsByIdsBatchHandler contract: rejects missing token, rejects
 * empty ids, forwards the parsed ids + page to
 * SyncRequest::brandsByIds(), and passes through the service's result shape.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Admin\Ajax;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DataFlair\Toplists\Admin\Ajax\SyncBrandsByIdsBatchHandler;
use DataFlair\Toplists\Sync\BrandSyncServiceInterface;
use DataFlair\Toplists\Sync\SyncRequest;
use DataFlair\Toplists\Sync\SyncResult;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/AjaxHandlerInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/SyncRequest.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/SyncResult.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/BrandSyncOutcome.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/BrandSyncServiceInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Ajax/SyncBrandsByIdsBatchHandler.php';

final class SyncBrandsByIdsBatchHandlerTest extends TestCase
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

    public function test_rejects_missing_api_token(): void
    {
        Functions\when('get_option')->alias(static fn ($key, $default = false) => '');

        $result = (new SyncBrandsByIdsBatchHandler(new SpySyncService()))
            ->handle(['api_brand_ids' => [1, 2], 'page' => 1]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('token', strtolower($result['data']['message']));
    }

    public function test_rejects_empty_brand_ids(): void
    {
        Functions\when('get_option')->alias(static fn ($key, $default = false) => 'test-token');

        $service = new SpySyncService();
        $result  = (new SyncBrandsByIdsBatchHandler($service))->handle(['api_brand_ids' => []]);

        $this->assertFalse($result['success']);
        $this->assertNull($service->received, 'the service must never be called with no ids');
    }

    public function test_forwards_parsed_ids_and_page_to_a_brands_by_ids_request(): void
    {
        Functions\when('get_option')->alias(static fn ($key, $default = false) => 'test-token');

        $service = new SpySyncService();
        (new SyncBrandsByIdsBatchHandler($service))
            ->handle(['api_brand_ids' => ['5', '6', '5', 'not-a-number'], 'page' => 3]);

        $this->assertNotNull($service->received);
        $this->assertSame('brands', $service->received->type);
        $this->assertSame(3, $service->received->page);
        // Deduped, cast to int; the non-numeric string casts to 0 and is filtered out.
        $this->assertSame([5, 6], $service->received->ids);
    }

    public function test_defaults_to_page_one_when_page_is_omitted(): void
    {
        Functions\when('get_option')->alias(static fn ($key, $default = false) => 'test-token');

        $service = new SpySyncService();
        (new SyncBrandsByIdsBatchHandler($service))->handle(['api_brand_ids' => [1]]);

        $this->assertSame(1, $service->received->page);
    }

    public function test_success_result_passes_through_service_payload(): void
    {
        Functions\when('get_option')->alias(static fn ($key, $default = false) => 'test-token');

        $service = new SpySyncService(SyncResult::success(1, 1, 2, 0, false, true, ['total_synced' => 2, 'total_brands' => 2]));
        $result  = (new SyncBrandsByIdsBatchHandler($service))->handle(['api_brand_ids' => [1, 2]]);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['synced']);
        $this->assertTrue($result['data']['is_complete']);
    }

    public function test_failure_result_surfaces_the_service_message(): void
    {
        Functions\when('get_option')->alias(static fn ($key, $default = false) => 'test-token');

        $service = new SpySyncService(SyncResult::failure(1, 'API unreachable'));
        $result  = (new SyncBrandsByIdsBatchHandler($service))->handle(['api_brand_ids' => [1]]);

        $this->assertFalse($result['success']);
        $this->assertSame('API unreachable', $result['data']['message']);
    }
}

final class SpySyncService implements BrandSyncServiceInterface
{
    public ?SyncRequest $received = null;

    public function __construct(private ?SyncResult $result = null)
    {
    }

    public function syncPage(SyncRequest $request): SyncResult
    {
        $this->received = $request;

        return $this->result ?? SyncResult::success($request->page, $request->page, 0, 0, false, true);
    }

    public function syncOne(int $apiBrandId): \DataFlair\Toplists\Sync\BrandSyncOutcome
    {
        return \DataFlair\Toplists\Sync\BrandSyncOutcome::active($apiBrandId);
    }
}
