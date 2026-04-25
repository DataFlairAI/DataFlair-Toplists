<?php
/**
 * Phase 5 — pins GetAvailableGeosHandler behaviour.
 *
 * Single responsibility: forward ToplistsRepository::collectGeoNames() to
 * the wire in the shape the admin JS expects (`['geos' => [...]]`).
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Admin\Ajax;

use DataFlair\Toplists\Admin\Ajax\GetAvailableGeosHandler;
use DataFlair\Toplists\Database\ToplistsRepositoryInterface;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/ToplistsQuery.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/ToplistsPage.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/ToplistsRepositoryInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/AjaxHandlerInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Ajax/GetAvailableGeosHandler.php';

final class GetAvailableGeosHandlerTest extends TestCase
{
    public function test_returns_geos_from_repository(): void
    {
        $repo = $this->repoReturning(['US', 'DE', 'UK']);

        $handler = new GetAvailableGeosHandler($repo);
        $result  = $handler->handle([]);

        $this->assertTrue($result['success']);
        $this->assertSame(['US', 'DE', 'UK'], $result['data']['geos']);
    }

    public function test_returns_empty_array_when_repository_empty(): void
    {
        $repo = $this->repoReturning([]);

        $handler = new GetAvailableGeosHandler($repo);
        $result  = $handler->handle([]);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['data']['geos']);
    }

    private function repoReturning(array $geos): ToplistsRepositoryInterface
    {
        return new class($geos) implements ToplistsRepositoryInterface {
            public function __construct(private array $geos) {}
            public function collectGeoNames(): array { return $this->geos; }
            public function findByApiToplistId(int $api_toplist_id): ?array { return null; }
            public function findBySlug(string $slug): ?array { return null; }
            public function upsert(array $row) { return false; }
            public function deleteByApiToplistId(int $api_toplist_id): bool { return false; }
            public function listAllForOptions(): array { return []; }
            public function countAll(): int { return 0; }
            public function findPaginated(\DataFlair\Toplists\Database\ToplistsQuery $q): \DataFlair\Toplists\Database\ToplistsPage { return new \DataFlair\Toplists\Database\ToplistsPage([], 0, 1, 25); }
            public function findItemSummaryByApiToplistId(int $id): array { return []; }
            public function findRawDataByApiToplistId(int $id): ?array { return null; }
        };
    }
}
