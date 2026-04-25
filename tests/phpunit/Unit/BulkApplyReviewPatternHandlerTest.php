<?php
/**
 * Phase 9.6 (admin UX redesign) — pins BulkApplyReviewPatternHandler contract.
 *
 * Verifies: pattern validation, slug token replacement, per-brand persistence,
 * and correct updated count in the response.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Admin\Ajax;

use DataFlair\Toplists\Admin\Ajax\BulkApplyReviewPatternHandler;
use DataFlair\Toplists\Database\BrandsRepositoryInterface;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/BrandsRepositoryInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/BrandsPage.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/BrandsQuery.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/AjaxHandlerInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Ajax/BulkApplyReviewPatternHandler.php';

final class BulkApplyReviewPatternHandlerTest extends TestCase
{
    public function test_rejects_pattern_without_slug_token(): void
    {
        $repo    = $this->createMock(BrandsRepositoryInterface::class);
        $repo->expects($this->never())->method('findManyByApiBrandIds');

        $result = (new BulkApplyReviewPatternHandler($repo))->handle([
            'api_brand_ids' => [1],
            'pattern'       => '/reviews/no-token/',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('{slug}', $result['data']['message']);
    }

    public function test_rejects_empty_brand_ids(): void
    {
        $repo = $this->createMock(BrandsRepositoryInterface::class);
        $repo->expects($this->never())->method('findManyByApiBrandIds');

        $result = (new BulkApplyReviewPatternHandler($repo))->handle([
            'api_brand_ids' => [],
            'pattern'       => '/reviews/{slug}/',
        ]);

        $this->assertFalse($result['success']);
    }

    public function test_replaces_slug_token_per_brand(): void
    {
        $repo = $this->createMock(BrandsRepositoryInterface::class);
        $repo->method('findManyByApiBrandIds')
             ->willReturn([
                 10 => ['slug' => 'betway'],
                 20 => ['slug' => 'bet365'],
             ]);

        $saved = [];
        $repo->method('updateReviewUrlOverrideByApiBrandId')
             ->willReturnCallback(function (int $id, string $url) use (&$saved) {
                 $saved[$id] = $url;
                 return true;
             });

        (new BulkApplyReviewPatternHandler($repo))->handle([
            'api_brand_ids' => [10, 20],
            'pattern'       => '/reviews/{slug}/',
        ]);

        $this->assertSame('/reviews/betway/', $saved[10]);
        $this->assertSame('/reviews/bet365/', $saved[20]);
    }

    public function test_returns_updated_count(): void
    {
        $repo = $this->createMock(BrandsRepositoryInterface::class);
        $repo->method('findManyByApiBrandIds')
             ->willReturn([
                 1 => ['slug' => 'brand-a'],
                 2 => ['slug' => 'brand-b'],
             ]);
        $repo->method('updateReviewUrlOverrideByApiBrandId')->willReturn(true);

        $result = (new BulkApplyReviewPatternHandler($repo))->handle([
            'api_brand_ids' => [1, 2],
            'pattern'       => '/reviews/{slug}/',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['updated']);
    }

    public function test_skips_brands_without_slug(): void
    {
        $repo = $this->createMock(BrandsRepositoryInterface::class);
        $repo->method('findManyByApiBrandIds')
             ->willReturn([
                 1 => ['slug' => ''],
                 2 => ['slug' => 'brand-b'],
             ]);
        $repo->method('updateReviewUrlOverrideByApiBrandId')->willReturn(true);

        $result = (new BulkApplyReviewPatternHandler($repo))->handle([
            'api_brand_ids' => [1, 2],
            'pattern'       => '/reviews/{slug}/',
        ]);

        $this->assertSame(1, $result['data']['updated']);
    }
}
