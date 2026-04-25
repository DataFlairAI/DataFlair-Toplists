<?php
/**
 * Phase 9.6 (admin UX redesign) — Trigger a full brands re-sync.
 *
 * Input: { api_brand_ids: int[] }
 * Phase 2 implementation kicks off a full brands sync (page 1 onwards) via
 * the existing batch-sync mechanism. Subset-only sync is deferred to Phase 4
 * when syncByApiBrandIds() is added to BrandSyncServiceInterface.
 *
 * Returns { start_batch: true } so brands.js delegates to the same sync
 * flow used by the "Sync Brands" button.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Ajax;

use DataFlair\Toplists\Admin\AjaxHandlerInterface;

final class BulkResyncBrandsHandler implements AjaxHandlerInterface
{
    public function handle(array $request): array
    {
        $token = trim((string) get_option('dataflair_api_token'));
        if ($token === '') {
            return ['success' => false, 'data' => ['message' => 'API token not configured.']];
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
