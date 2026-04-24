<?php
/**
 * Phase 3 — pins the AlternativesSyncService admin-facing contract.
 *
 * The service is a thin wrapper around AlternativesRepository; the
 * only logic it owns is input-guarding (toplist_id > 0, geo required)
 * and logger-side warnings. These tests pin both.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Sync;

use DataFlair\Toplists\Database\AlternativesRepositoryInterface;
use DataFlair\Toplists\Logging\LoggerInterface;
use DataFlair\Toplists\Logging\NullLogger;
use DataFlair\Toplists\Sync\AlternativesSyncService;
use DataFlair\Toplists\Sync\AlternativesSyncServiceInterface;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/NullLogger.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/AlternativesRepositoryInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/AlternativesSyncServiceInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/AlternativesSyncService.php';

final class AlternativesSyncServiceTest extends TestCase
{
    public function test_implements_interface(): void
    {
        $svc = new AlternativesSyncService($this->fakeRepo([]), new NullLogger());
        $this->assertInstanceOf(AlternativesSyncServiceInterface::class, $svc);
    }

    public function test_find_by_toplist_id_returns_empty_for_non_positive_ids(): void
    {
        $repo = new class implements AlternativesRepositoryInterface {
            public int $findCalls = 0;
            public function findByToplistId(int $t): array { $this->findCalls++; return []; }
            public function findByToplistAndGeo(int $t, string $g): ?array { return null; }
            public function upsert(array $r) { return false; }
            public function deleteByToplistId(int $t): bool { return false; }
        };

        $svc = new AlternativesSyncService($repo, new NullLogger());

        $this->assertSame([], $svc->findByToplistId(0));
        $this->assertSame([], $svc->findByToplistId(-1));
        $this->assertSame(0, $repo->findCalls, 'Repo must not be hit for invalid IDs.');
    }

    public function test_find_by_toplist_id_delegates_for_positive_id(): void
    {
        $rows = [['id' => 1, 'geo' => 'US'], ['id' => 2, 'geo' => 'DE']];
        $repo = $this->fakeRepo($rows);

        $svc = new AlternativesSyncService($repo, new NullLogger());

        $this->assertSame($rows, $svc->findByToplistId(7));
    }

    public function test_save_rejects_missing_toplist_id(): void
    {
        $logger = new class implements LoggerInterface {
            public array $warnings = [];
            public function emergency(string $m, array $c = []): void {}
            public function alert(string $m, array $c = []): void {}
            public function critical(string $m, array $c = []): void {}
            public function error(string $m, array $c = []): void {}
            public function warning(string $m, array $c = []): void { $this->warnings[] = $m; }
            public function notice(string $m, array $c = []): void {}
            public function info(string $m, array $c = []): void {}
            public function debug(string $m, array $c = []): void {}
        };

        $svc = new AlternativesSyncService($this->fakeRepo([]), $logger);

        $this->assertFalse($svc->save(['geo' => 'US']));
        $this->assertNotEmpty($logger->warnings);
    }

    public function test_save_rejects_missing_geo(): void
    {
        $logger = new class implements LoggerInterface {
            public array $warnings = [];
            public function emergency(string $m, array $c = []): void {}
            public function alert(string $m, array $c = []): void {}
            public function critical(string $m, array $c = []): void {}
            public function error(string $m, array $c = []): void {}
            public function warning(string $m, array $c = []): void { $this->warnings[] = $m; }
            public function notice(string $m, array $c = []): void {}
            public function info(string $m, array $c = []): void {}
            public function debug(string $m, array $c = []): void {}
        };

        $svc = new AlternativesSyncService($this->fakeRepo([]), $logger);

        $this->assertFalse($svc->save(['toplist_id' => 5]));
        $this->assertNotEmpty($logger->warnings);
    }

    public function test_save_delegates_valid_payload_to_repo(): void
    {
        $repo = new class implements AlternativesRepositoryInterface {
            public array $upserted = [];
            public function findByToplistId(int $t): array { return []; }
            public function findByToplistAndGeo(int $t, string $g): ?array { return null; }
            public function upsert(array $r) { $this->upserted = $r; return 42; }
            public function deleteByToplistId(int $t): bool { return false; }
        };

        $svc = new AlternativesSyncService($repo, new NullLogger());

        $this->assertSame(42, $svc->save(['toplist_id' => 5, 'geo' => 'US', 'name' => 'US alt']));
        $this->assertSame('US', $repo->upserted['geo']);
    }

    public function test_delete_rejects_non_positive_ids(): void
    {
        $repo = new class implements AlternativesRepositoryInterface {
            public int $deleteCalls = 0;
            public function findByToplistId(int $t): array { return []; }
            public function findByToplistAndGeo(int $t, string $g): ?array { return null; }
            public function upsert(array $r) { return false; }
            public function deleteByToplistId(int $t): bool { $this->deleteCalls++; return true; }
        };

        $svc = new AlternativesSyncService($repo, new NullLogger());

        $this->assertFalse($svc->deleteByToplistId(0));
        $this->assertFalse($svc->deleteByToplistId(-5));
        $this->assertSame(0, $repo->deleteCalls);
    }

    public function test_delete_delegates_for_positive_id(): void
    {
        $repo = new class implements AlternativesRepositoryInterface {
            public int $lastDeleted = 0;
            public function findByToplistId(int $t): array { return []; }
            public function findByToplistAndGeo(int $t, string $g): ?array { return null; }
            public function upsert(array $r) { return false; }
            public function deleteByToplistId(int $t): bool { $this->lastDeleted = $t; return true; }
        };

        $svc = new AlternativesSyncService($repo, new NullLogger());

        $this->assertTrue($svc->deleteByToplistId(11));
        $this->assertSame(11, $repo->lastDeleted);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private function fakeRepo(array $rows): AlternativesRepositoryInterface
    {
        return new class($rows) implements AlternativesRepositoryInterface {
            public function __construct(private readonly array $rows) {}
            public function findByToplistId(int $t): array { return $this->rows; }
            public function findByToplistAndGeo(int $t, string $g): ?array { return null; }
            public function upsert(array $r) { return 1; }
            public function deleteByToplistId(int $t): bool { return true; }
        };
    }
}
