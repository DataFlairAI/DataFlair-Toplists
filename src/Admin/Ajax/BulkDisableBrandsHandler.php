<?php
/**
 * Phase 9.6 (admin UX redesign) — Bulk enable/disable selected brands.
 *
 * Input: { api_brand_ids: int[], disabled: bool }
 * Calls BrandsRepository::setDisabledByApiBrandIds() and returns the
 * affected row count.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Ajax;

use DataFlair\Toplists\Admin\AjaxHandlerInterface;
use DataFlair\Toplists\Database\BrandsRepositoryInterface;

final class BulkDisableBrandsHandler implements AjaxHandlerInterface
{
    public function __construct(private BrandsRepositoryInterface $repo) {}

    public function handle(array $request): array
    {
        $raw_ids  = isset($request['api_brand_ids']) ? (array) $request['api_brand_ids'] : [];
        $ids      = array_values(array_filter(array_map('intval', $raw_ids)));
        $disabled = isset($request['disabled']) ? (bool) $request['disabled'] : true;

        if (empty($ids)) {
            return ['success' => false, 'data' => ['message' => 'No brand IDs provided.']];
        }

        $affected = $this->repo->setDisabledByApiBrandIds($ids, $disabled);
        return ['success' => true, 'data' => ['affected' => $affected, 'disabled' => $disabled]];
    }
}
