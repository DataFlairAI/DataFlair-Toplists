<?php
/**
 * Phase 9.6 (admin UX redesign) — Pre-flight check for "re-sync selected".
 *
 * Input: { api_brand_ids: int[] }
 * Validates the token, API config, and that ids were actually provided,
 * then returns { start_batch: true } so brands.js starts the real
 * selected-ids batch loop against dataflair_sync_brands_by_ids_batch
 * (SyncBrandsByIdsBatchHandler / SyncRequest::brandsByIds()), which only
 * touches the selected brands - never a full-catalog resync.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Ajax;

use DataFlair\Toplists\Admin\AjaxHandlerInterface;

final class BulkResyncBrandsHandler implements AjaxHandlerInterface
{
    public function __construct(private \Closure $isApiConfigured)
    {
    }

    public function handle(array $request): array
    {
        $token = trim((string) get_option('dataflair_api_token'));
        if ($token === '') {
            return ['success' => false, 'data' => ['message' => 'API token not configured.']];
        }

        if (! ($this->isApiConfigured)()) {
            return ['success' => false, 'data' => ['message' => 'API Base URL is not configured. Set it in Settings before syncing.']];
        }

        $raw_ids = isset($request['api_brand_ids']) ? (array) $request['api_brand_ids'] : [];
        $count   = count(array_filter(array_map('intval', $raw_ids)));
        if ($count === 0) {
            return ['success' => false, 'data' => ['message' => 'No brand IDs provided.']];
        }

        return [
            'success' => true,
            'data'    => [
                'message'     => 'Brands sync initiated for ' . $count . ' selected brand(s).',
                'start_batch' => true,
            ],
        ];
    }
}
