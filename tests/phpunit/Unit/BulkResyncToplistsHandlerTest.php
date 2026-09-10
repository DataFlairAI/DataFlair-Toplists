<?php
/**
 * Pins BulkResyncToplistsHandler's guards: rejects a missing token, and
 * rejects an unconfigured API base URL BEFORE ever calling the sync
 * service — proven by a stub that throws if invoked, not just by
 * asserting the response shape (found missing in the second max-review
 * pass of v2.3.3).
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Admin\Ajax;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DataFlair\Toplists\Admin\Ajax\BulkResyncToplistsHandler;
use DataFlair\Toplists\Sync\SyncRequest;
use DataFlair\Toplists\Sync\SyncResult;
use DataFlair\Toplists\Sync\ToplistSyncServiceInterface;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/AjaxHandlerInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/SyncRequest.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/SyncResult.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/ToplistSyncServiceInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Ajax/BulkResyncToplistsHandler.php';

final class BulkResyncToplistsHandlerTest extends TestCase
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

    private function neverCalledSync(): ToplistSyncServiceInterface
    {
        return new class implements ToplistSyncServiceInterface {
            public function syncPage(SyncRequest $request): SyncResult
            {
                throw new \RuntimeException('syncPage must not be called when the guard should have short-circuited.');
            }

            public function syncByApiToplistIds(array $apiToplistIds, int $budgetSeconds = 60): SyncResult
            {
                throw new \RuntimeException('syncByApiToplistIds must not be called when the guard should have short-circuited.');
            }
        };
    }

    public function test_rejects_missing_api_token_without_calling_sync(): void
    {
        Functions\when('get_option')->alias(static fn($key, $default = false) => '');

        $handler = new BulkResyncToplistsHandler($this->neverCalledSync(), static fn (): bool => true);
        $result  = $handler->handle(['api_toplist_ids' => [1, 2]]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('token', strtolower($result['data']['message']));
    }

    public function test_rejects_when_api_base_url_is_not_configured_without_calling_sync(): void
    {
        Functions\when('get_option')->alias(static fn($key, $default = false) => 'test-token');

        $handler = new BulkResyncToplistsHandler($this->neverCalledSync(), static fn (): bool => false);
        $result  = $handler->handle(['api_toplist_ids' => [1, 2]]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('API Base URL is not configured', $result['data']['message']);
    }

    public function test_rejects_empty_toplist_ids_without_calling_sync(): void
    {
        Functions\when('get_option')->alias(static fn($key, $default = false) => 'test-token');

        $handler = new BulkResyncToplistsHandler($this->neverCalledSync(), static fn (): bool => true);
        $result  = $handler->handle(['api_toplist_ids' => []]);

        $this->assertFalse($result['success']);
    }

    public function test_syncs_when_token_and_base_url_are_configured(): void
    {
        Functions\when('get_option')->alias(static fn($key, $default = false) => 'test-token');

        $sync = new class implements ToplistSyncServiceInterface {
            public function syncPage(SyncRequest $request): SyncResult
            {
                throw new \RuntimeException('not used in this test');
            }

            public function syncByApiToplistIds(array $apiToplistIds, int $budgetSeconds = 60): SyncResult
            {
                return SyncResult::success(1, 1, count($apiToplistIds), 0, false, true, []);
            }
        };

        $handler = new BulkResyncToplistsHandler($sync, static fn (): bool => true);
        $result  = $handler->handle(['api_toplist_ids' => [1, 2]]);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['synced']);
    }
}
