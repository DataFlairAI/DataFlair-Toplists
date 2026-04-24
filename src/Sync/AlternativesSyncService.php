<?php
/**
 * Alternative-toplists write pipeline. Wraps the AlternativesRepository for
 * the admin CRUD paths (Phase 3). Alternatives are curated by hand — there
 * is no API-backed sync — so this service is a thin lookup/save/delete shell
 * that Phase 5's AJAX router will delegate into.
 *
 * @package DataFlair\Toplists\Sync
 * @since   1.12.1 (Phase 3)
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Sync;

use DataFlair\Toplists\Database\AlternativesRepositoryInterface;
use DataFlair\Toplists\Logging\LoggerInterface;

final class AlternativesSyncService implements AlternativesSyncServiceInterface
{
    public function __construct(
        private readonly AlternativesRepositoryInterface $alternatives,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * @return array<int,array<string,mixed>>
     */
    public function findByToplistId(int $toplist_id): array
    {
        if ($toplist_id <= 0) {
            return [];
        }
        return $this->alternatives->findByToplistId($toplist_id);
    }

    /**
     * @param array<string,mixed> $row
     * @return int|false
     */
    public function save(array $row)
    {
        if (empty($row['toplist_id']) || empty($row['geo'])) {
            $this->logger->warning('AlternativesSync: save rejected — toplist_id + geo required');
            return false;
        }
        return $this->alternatives->upsert($row);
    }

    public function deleteByToplistId(int $toplist_id): bool
    {
        if ($toplist_id <= 0) {
            return false;
        }
        return $this->alternatives->deleteByToplistId($toplist_id);
    }
}
