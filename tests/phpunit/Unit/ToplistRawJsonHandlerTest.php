<?php
/**
 * Phase 9.6 (admin UX redesign) — pins ToplistRawJsonHandler contract.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Admin\Ajax;

use Brain\Monkey;
use DataFlair\Toplists\Admin\Ajax\ToplistRawJsonHandler;
use DataFlair\Toplists\Database\ToplistsRepositoryInterface;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/AjaxHandlerInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/ToplistsRepositoryInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/ToplistsQuery.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/ToplistsPage.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Ajax/ToplistRawJsonHandler.php';

final class ToplistRawJsonHandlerTest extends TestCase
{
    protected function setUp(): void    { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    public function test_rejects_missing_id(): void
    {
        $repo = $this->createStub(ToplistsRepositoryInterface::class);
        $result = (new ToplistRawJsonHandler($repo))->handle([]);
        $this->assertFalse($result['success']);
    }

    public function test_returns_error_when_not_found(): void
    {
        $repo = $this->createStub(ToplistsRepositoryInterface::class);
        $repo->method('findRawDataByApiToplistId')->willReturn(null);

        $result = (new ToplistRawJsonHandler($repo))->handle(['api_toplist_id' => 99]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not found', strtolower($result['data']['message']));
    }

    public function test_returns_pretty_json_for_known_toplist(): void
    {
        $data = ['data' => ['items' => [['position' => 1, 'brand_id' => 5]]]];
        $repo = $this->createStub(ToplistsRepositoryInterface::class);
        $repo->method('findRawDataByApiToplistId')->willReturn($data);

        $result = (new ToplistRawJsonHandler($repo))->handle(['api_toplist_id' => 1]);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['api_toplist_id']);
        $this->assertStringContainsString('"items"', $result['data']['json']);
        // Verify it's pretty-printed (has newlines)
        $this->assertStringContainsString("\n", $result['data']['json']);
    }
}
