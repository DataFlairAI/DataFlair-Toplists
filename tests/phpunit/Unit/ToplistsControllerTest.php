<?php
/**
 * Phase 6 — pins the GET /wp-json/dataflair/v1/toplists response shape.
 *
 * Locks in:
 *   - Returns a flat array of `{value, label}` pairs.
 *   - `value` is the upstream api_toplist_id coerced to a string.
 *   - `label` is "{name} [slug]" when slug is present, otherwise "{name} (ID: {n})".
 *   - Rows are consumed in the order the repository returns them (repository
 *     is responsible for ORDER BY).
 *   - A repository exception is caught and translated to WP_Error with status 500.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Rest\Controllers;

use DataFlair\Toplists\Database\ToplistsRepositoryInterface;
use DataFlair\Toplists\Logging\NullLogger;
use DataFlair\Toplists\Rest\Controllers\ToplistsController;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/NullLogger.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/ToplistsQuery.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/ToplistsPage.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/ToplistsRepositoryInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Rest/Controllers/ToplistsController.php';
require_once __DIR__ . '/RestControllerTestStubs.php';

final class ToplistsControllerTest extends TestCase
{
    public function test_returns_value_label_pairs_with_slug_suffix(): void
    {
        $repo = $this->repoReturning([
            ['api_toplist_id' => 7,  'name' => 'Casinos EU', 'slug' => 'casinos-eu'],
            ['api_toplist_id' => 12, 'name' => 'Sportsbook AU', 'slug' => 'sport-au'],
        ]);

        $response = (new ToplistsController($repo, new NullLogger()))->list();

        $this->assertInstanceOf(\WP_REST_Response::class, $response);
        $this->assertSame(
            [
                ['value' => '7',  'label' => 'Casinos EU [casinos-eu]'],
                ['value' => '12', 'label' => 'Sportsbook AU [sport-au]'],
            ],
            $response->get_data()
        );
    }

    public function test_falls_back_to_id_suffix_when_slug_is_empty(): void
    {
        $repo = $this->repoReturning([
            ['api_toplist_id' => 42, 'name' => 'Legacy Mixed', 'slug' => ''],
        ]);

        $response = (new ToplistsController($repo, new NullLogger()))->list();

        $this->assertSame(
            [['value' => '42', 'label' => 'Legacy Mixed (ID: 42)']],
            $response->get_data()
        );
    }

    public function test_empty_repository_returns_empty_array(): void
    {
        $response = (new ToplistsController($this->repoReturning([]), new NullLogger()))->list();

        $this->assertSame([], $response->get_data());
    }

    public function test_repository_exception_is_translated_to_wp_error_500(): void
    {
        $repo = new class implements ToplistsRepositoryInterface {
            public function findByApiToplistId(int $api_toplist_id): ?array { return null; }
            public function findBySlug(string $slug): ?array { return null; }
            public function upsert(array $row) { return false; }
            public function deleteByApiToplistId(int $api_toplist_id): bool { return true; }
            public function collectGeoNames(): array { return []; }
            public function listAllForOptions(): array { throw new \RuntimeException('db is on fire'); }
            public function countAll(): int { return 0; }
            public function findPaginated(\DataFlair\Toplists\Database\ToplistsQuery $q): \DataFlair\Toplists\Database\ToplistsPage { return new \DataFlair\Toplists\Database\ToplistsPage([], 0, 1, 25); }
            public function findItemSummaryByApiToplistId(int $id): array { return []; }
            public function findRawDataByApiToplistId(int $id): ?array { return null; }
        };

        $result = (new ToplistsController($repo, new NullLogger()))->list();

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('exception', $result->get_error_code());
        $this->assertStringContainsString('db is on fire', $result->get_error_message());
        $this->assertSame(500, $result->data['status']);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private function repoReturning(array $rows): ToplistsRepositoryInterface
    {
        return new class($rows) implements ToplistsRepositoryInterface {
            public function __construct(private array $rows) {}
            public function findByApiToplistId(int $api_toplist_id): ?array { return null; }
            public function findBySlug(string $slug): ?array { return null; }
            public function upsert(array $row) { return false; }
            public function deleteByApiToplistId(int $api_toplist_id): bool { return true; }
            public function collectGeoNames(): array { return []; }
            public function listAllForOptions(): array { return $this->rows; }
            public function countAll(): int { return count($this->rows); }
            public function findPaginated(\DataFlair\Toplists\Database\ToplistsQuery $q): \DataFlair\Toplists\Database\ToplistsPage { return new \DataFlair\Toplists\Database\ToplistsPage([], 0, 1, 25); }
            public function findItemSummaryByApiToplistId(int $id): array { return []; }
            public function findRawDataByApiToplistId(int $id): ?array { return null; }
        };
    }
}
