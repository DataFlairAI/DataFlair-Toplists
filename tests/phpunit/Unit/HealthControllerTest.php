<?php
/**
 * Phase 6 — pins the /wp-json/dataflair/v1/health response shape.
 *
 * The endpoint is an operational probe. It must return:
 *   - status=ok
 *   - toplists count from the repository
 *   - plugin_ver constant
 *   - db_error null when $wpdb has none
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Rest\Controllers;

use DataFlair\Toplists\Database\ToplistsRepositoryInterface;
use DataFlair\Toplists\Rest\Controllers\HealthController;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/ToplistsQuery.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/ToplistsPage.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/ToplistsRepositoryInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Rest/Controllers/HealthController.php';
require_once __DIR__ . '/RestControllerTestStubs.php';

final class HealthControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb = new \wpdb();
    }

    public function test_returns_ok_status_with_count_and_plugin_version(): void
    {
        $repo = new class implements ToplistsRepositoryInterface {
            public function findByApiToplistId(int $api_toplist_id): ?array { return null; }
            public function findBySlug(string $slug): ?array { return null; }
            public function upsert(array $row) { return false; }
            public function deleteByApiToplistId(int $api_toplist_id): bool { return true; }
            public function collectGeoNames(): array { return []; }
            public function listAllForOptions(): array { return []; }
            public function countAll(): int { return 42; }
            public function findPaginated(\DataFlair\Toplists\Database\ToplistsQuery $q): \DataFlair\Toplists\Database\ToplistsPage { return new \DataFlair\Toplists\Database\ToplistsPage([], 0, 1, 25); }
            public function findItemSummaryByApiToplistId(int $id): array { return []; }
            public function findRawDataByApiToplistId(int $id): ?array { return null; }
            public function findFamilyByTemplateId(int $templateId): array { return []; }
        };

        $response = (new HealthController($repo))->status();

        $data = $response->get_data();
        $this->assertSame('ok', $data['status']);
        $this->assertSame(42, $data['toplists']);
        $this->assertSame(DATAFLAIR_VERSION, $data['plugin_ver']);
        $this->assertNull($data['db_error']);
        $this->assertNull($data['contract_mismatch']);
    }

    public function test_surfaces_contract_mismatch_state_when_recorded(): void
    {
        $GLOBALS['dataflair_rest_test_options'][\DataFlair\Toplists\Sync\ContractMismatch::OPTION] = [
            'message'            => 'Plugin below minimum version.',
            'min_plugin_version' => '2.5.0',
            'source'             => 'toplists',
        ];

        $repo = new class implements ToplistsRepositoryInterface {
            public function findByApiToplistId(int $api_toplist_id): ?array { return null; }
            public function findBySlug(string $slug): ?array { return null; }
            public function upsert(array $row) { return false; }
            public function deleteByApiToplistId(int $api_toplist_id): bool { return true; }
            public function collectGeoNames(): array { return []; }
            public function listAllForOptions(): array { return []; }
            public function countAll(): int { return 1; }
            public function findPaginated(\DataFlair\Toplists\Database\ToplistsQuery $q): \DataFlair\Toplists\Database\ToplistsPage { return new \DataFlair\Toplists\Database\ToplistsPage([], 0, 1, 25); }
            public function findItemSummaryByApiToplistId(int $id): array { return []; }
            public function findRawDataByApiToplistId(int $id): ?array { return null; }
            public function findFamilyByTemplateId(int $templateId): array { return []; }
        };

        $data = (new HealthController($repo))->status()->get_data();

        $this->assertIsArray($data['contract_mismatch']);
        $this->assertSame('2.5.0', $data['contract_mismatch']['min_plugin_version']);

        unset($GLOBALS['dataflair_rest_test_options']);
    }

    public function test_surfaces_wpdb_last_error_when_set(): void
    {
        global $wpdb;
        $wpdb->last_error = 'table dataflair_toplists is full';

        $repo = new class implements ToplistsRepositoryInterface {
            public function findByApiToplistId(int $api_toplist_id): ?array { return null; }
            public function findBySlug(string $slug): ?array { return null; }
            public function upsert(array $row) { return false; }
            public function deleteByApiToplistId(int $api_toplist_id): bool { return true; }
            public function collectGeoNames(): array { return []; }
            public function listAllForOptions(): array { return []; }
            public function countAll(): int { return 0; }
            public function findPaginated(\DataFlair\Toplists\Database\ToplistsQuery $q): \DataFlair\Toplists\Database\ToplistsPage { return new \DataFlair\Toplists\Database\ToplistsPage([], 0, 1, 25); }
            public function findItemSummaryByApiToplistId(int $id): array { return []; }
            public function findRawDataByApiToplistId(int $id): ?array { return null; }
            public function findFamilyByTemplateId(int $templateId): array { return []; }
        };

        $response = (new HealthController($repo))->status();

        $data = $response->get_data();
        $this->assertSame('table dataflair_toplists is full', $data['db_error']);
    }
}
