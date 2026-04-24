<?php
/**
 * Contract for alternative-toplist persistence on `wp_dataflair_alternative_toplists`.
 *
 * Phase 2 — a thin surface covering the lookup + upsert + cleanup calls
 * used by the sync service and the geo-aware alternative-toplist picker.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Database;

interface AlternativesRepositoryInterface
{
    /**
     * List all alternative rows linked to a parent toplist.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByToplistId(int $toplist_id): array;

    /**
     * Look up a single row by parent toplist + geo.
     *
     * @return array<string, mixed>|null
     */
    public function findByToplistAndGeo(int $toplist_id, string $geo): ?array;

    /**
     * Insert or update by the unique (toplist_id, geo) constraint.
     *
     * @param array<string, mixed> $row
     * @return int|false Row ID on success, `false` on failure.
     */
    public function upsert(array $row);

    /**
     * Delete every alternative row under a parent toplist.
     */
    public function deleteByToplistId(int $toplist_id): bool;

    /**
     * Delete a single alternative row by its primary key.
     */
    public function deleteById(int $id): bool;
}
