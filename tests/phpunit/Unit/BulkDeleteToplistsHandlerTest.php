<?php
/**
 * Phase 9.6 (admin UX redesign) — pins BulkDeleteToplistsHandler contract.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Admin\Ajax;

use Brain\Monkey;
use DataFlair\Toplists\Admin\Ajax\BulkDeleteToplistsHandler;
use DataFlair\Toplists\Database\ToplistsRepositoryInterface;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/AjaxHandlerInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/ToplistsRepositoryInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/ToplistsQuery.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/ToplistsPage.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Ajax/BulkDeleteToplistsHandler.php';

final class BulkDeleteToplistsHandlerTest extends TestCase
{
    protected function setUp(): void    { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    public function test_rejects_empty_ids(): void
    {
        $repo = $this->createStub(ToplistsRepositoryInterface::class);
        $result = (new BulkDeleteToplistsHandler($repo))->handle([]);
        $this->assertFalse($result['success']);
    }

    public function test_returns_deleted_count(): void
    {
        $repo = $this->createStub(ToplistsRepositoryInterface::class);
        $repo->method('deleteByApiToplistId')->willReturn(true);

        $result = (new BulkDeleteToplistsHandler($repo))->handle(['api_toplist_ids' => [1, 2, 3]]);

        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['data']['deleted']);
    }

    public function test_partial_delete_counts_only_successes(): void
    {
        $repo = $this->createStub(ToplistsRepositoryInterface::class);
        $repo->method('deleteByApiToplistId')->willReturnOnConsecutiveCalls(true, false, true);

        $result = (new BulkDeleteToplistsHandler($repo))->handle(['api_toplist_ids' => [1, 2, 3]]);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['deleted']);
    }
}
