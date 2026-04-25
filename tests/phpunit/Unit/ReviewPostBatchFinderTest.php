<?php
/**
 * Phase 9.9 — Pins ReviewPostBatchFinder repo delegation.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Frontend\Content;

use DataFlair\Toplists\Database\BrandsRepositoryInterface;
use DataFlair\Toplists\Frontend\Content\ReviewPostBatchFinder;
use Mockery as M;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/BrandsRepositoryInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Frontend/Content/ReviewPostBatchFinder.php';

final class ReviewPostBatchFinderTest extends TestCase
{
    protected function tearDown(): void
    {
        M::close();
        parent::tearDown();
    }

    public function test_delegates_to_repo_findReviewPostsByApiBrandIds(): void
    {
        $repo = M::mock(BrandsRepositoryInterface::class);
        $repo->shouldReceive('findReviewPostsByApiBrandIds')
            ->once()
            ->with([100, 200])
            ->andReturn([100 => 555, 200 => 666]);

        $finder = new ReviewPostBatchFinder($repo);
        $result = $finder->findByApiBrandIds([100, 200]);

        $this->assertSame([100 => 555, 200 => 666], $result);
    }

    public function test_passes_through_empty_input(): void
    {
        $repo = M::mock(BrandsRepositoryInterface::class);
        $repo->shouldReceive('findReviewPostsByApiBrandIds')
            ->once()
            ->with([])
            ->andReturn([]);

        $finder = new ReviewPostBatchFinder($repo);
        $this->assertSame([], $finder->findByApiBrandIds([]));
    }
}
