<?php
/**
 * Contract for toplist-row persistence on `wp_dataflair_toplists`.
 *
 * Phase 2 — extracted from god-class sync + render paths. Focuses on the
 * lookups and writes used by the sync service and the render shortcode.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Database;

interface ToplistsRepositoryInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function findByApiToplistId(int $api_toplist_id): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array;

    /**
     * Persist a toplist row (insert or update by unique `api_toplist_id`).
     *
     * @param array<string, mixed> $row
     * @return int|false Row ID on success, `false` on failure.
     */
    public function upsert(array $row);

    /**
     * Delete by upstream DataFlair toplist ID.
     */
    public function deleteByApiToplistId(int $api_toplist_id): bool;

    /**
     * Extract the distinct set of geo names referenced inside every toplist's
     * persisted `data` column. Used by the admin alternative-toplist picker
     * to populate the geo dropdown without reaching out to the upstream API.
     *
     * @return array<int,string> alphabetically sorted, unique.
     */
    public function collectGeoNames(): array;
}
