<?php
/**
 * Contract for the brand sync pipeline.
 *
 * @package DataFlair\Toplists\Sync
 * @since   1.12.1 (Phase 3)
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Sync;

interface BrandSyncServiceInterface
{
    /**
     * Sync a single page of brands. Implementation MUST preserve every Phase 0B
     * / Phase 1 invariant: 15 MB/12 s HTTP cap, 25 s WallClockBudget with 3 s
     * headroom, dataflair_sync_batch_* telemetry hooks, logo download via
     * LogoDownloaderInterface, paginated DELETE when $page === 1 (full syncs
     * only - see SyncRequest::brandsByIds()), brand row upsert via
     * BrandsRepositoryInterface.
     */
    public function syncPage(SyncRequest $request): SyncResult;

    /**
     * Fetch and upsert one brand via the single-brand endpoint (bypasses the
     * list endpoint's active() scope), then set its local is_disabled flag
     * to match — active clears it, inactive or gone (404) sets it. Webhook
     * sync slice's brand.status_changed/brand.updated handler.
     */
    public function syncOne(int $apiBrandId): BrandSyncOutcome;
}
