<?php
/**
 * Contract for the alternative-toplists write pipeline.
 *
 * Alternatives are admin-curated — there is no API-backed sync. This service
 * wraps the persistence-side operations (list, upsert, delete) so Phase 5's
 * AJAX router can delegate into a single collaborator instead of reaching
 * through the god-class directly.
 *
 * @package DataFlair\Toplists\Sync
 * @since   1.12.1 (Phase 3)
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Sync;

interface AlternativesSyncServiceInterface
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public function findByToplistId(int $toplist_id): array;

    /**
     * Upsert an alternative row. Returns the row id on success, false on failure.
     * Requires both `toplist_id` and `geo` keys in the payload.
     *
     * @param array<string,mixed> $row
     * @return int|false
     */
    public function save(array $row);

    public function deleteByToplistId(int $toplist_id): bool;
}
