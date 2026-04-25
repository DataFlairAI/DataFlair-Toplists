<?php
/**
 * Phase 9.6 (admin UX redesign) — pins BulkDisableBrandsHandler contract.
 *
 * Verifies: delegates to setDisabledByApiBrandIds, returns affected count,
 * and rejects an empty ID list.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Admin\Ajax;

use DataFlair\Toplists\Admin\Ajax\BulkDisableBrandsHandler;
use DataFlair\Toplists\Database\BrandsRepositoryInterface;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/BrandsRepositoryInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/BrandsPage.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/BrandsQuery.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/AjaxHandlerInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Ajax/BulkDisableBrandsHandler.php';

final class BulkDisableBrandsHandlerTest extends TestCase
{
    public function test_rejects_empty_ids(): void
    {
        $repo = $this->createMock(BrandsRepositoryInterface::class);
        $repo->expects($this->never())->method('setDisabledByApiBrandIds');

        $result = (new BulkDisableBrandsHandler($repo))->handle(['api_brand_ids' => []]);

        $this->assertFalse($result['success']);
    }

    public function test_disables_brands_and_returns_affected_count(): void
    {
        $repo = $this->createMock(BrandsRepositoryInterface::class);
        $repo->expects($this->once())
             ->method('setDisabledByApiBrandIds')
             ->with([1, 2, 3], true)
             ->willReturn(3);

        $result = (new BulkDisableBrandsHandler($repo))->handle([
            'api_brand_ids' => [1, 2, 3],
            'disabled'      => 1,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['data']['affected']);
        $this->assertTrue($result['data']['disabled']);
    }

    public function test_enables_brands_when_disabled_is_false(): void
    {
        $repo = $this->createMock(BrandsRepositoryInterface::class);
        $repo->expects($this->once())
             ->method('setDisabledByApiBrandIds')
             ->with([5], false)
             ->willReturn(1);

        $result = (new BulkDisableBrandsHandler($repo))->handle([
            'api_brand_ids' => [5],
            'disabled'      => 0,
        ]);

        $this->assertFalse($result['data']['disabled']);
        $this->assertSame(1, $result['data']['affected']);
    }

    public function test_non_integer_ids_are_filtered_to_integers(): void
    {
        $repo = $this->createMock(BrandsRepositoryInterface::class);
        $repo->expects($this->once())
             ->method('setDisabledByApiBrandIds')
             ->with([10, 20], true)
             ->willReturn(2);

        (new BulkDisableBrandsHandler($repo))->handle([
            'api_brand_ids' => ['10', '20'],
            'disabled'      => 1,
        ]);
    }
}
