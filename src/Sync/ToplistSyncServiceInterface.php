<?php
/**
 * Contract for the toplist sync pipeline.
 *
 * @package DataFlair\Toplists\Sync
 * @since   1.12.1 (Phase 3)
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Sync;

interface ToplistSyncServiceInterface
{
    /**
     * Sync a single page of toplists. Implementation MUST preserve every Phase 0B
     * / Phase 1 invariant: 15 MB/12 s HTTP cap, 25 s WallClockBudget with 3 s
     * headroom, progressive-split fallback on 5xx, dataflair_sync_batch_*
     * telemetry hooks, option rename fallback on completion, paginated DELETE
     * when $page === 1.
     */
    public function syncPage(SyncRequest $request): SyncResult;

    /**
     * Re-sync a specific subset of toplists by their API IDs.
     * Fetches each individually from /toplists/{id} and persists it.
     * Does NOT delete or reset existing data.
     *
     * @param int[] $apiToplistIds
     */
    public function syncByApiToplistIds(array $apiToplistIds, int $budgetSeconds = 60): SyncResult;
}
