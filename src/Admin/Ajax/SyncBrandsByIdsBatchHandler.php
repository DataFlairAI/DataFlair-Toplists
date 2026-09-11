<?php
/**
 * Sync one page of a selected-ids brands run ("re-sync selected"). Mirrors
 * SyncBrandsBatchHandler but pins the request to specific api_brand_ids via
 * SyncRequest::brandsByIds(), which BrandSyncService never wipes local rows
 * for - only a full sync's page 1 does that.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Ajax;

use DataFlair\Toplists\Admin\AjaxHandlerInterface;
use DataFlair\Toplists\Sync\BrandSyncServiceInterface;
use DataFlair\Toplists\Sync\SyncRequest;

final class SyncBrandsByIdsBatchHandler implements AjaxHandlerInterface
{
    public function __construct(private BrandSyncServiceInterface $service) {}

    public function handle(array $request): array
    {
        $token = trim((string) get_option('dataflair_api_token'));
        if ($token === '') {
            return [
                'success' => false,
                'data'    => ['message' => 'API token not configured. Please set your API token first.'],
            ];
        }

        $rawIds = isset($request['api_brand_ids']) ? (array) $request['api_brand_ids'] : [];
        $ids    = array_values(array_unique(array_filter(array_map('intval', $rawIds))));
        if ($ids === []) {
            return ['success' => false, 'data' => ['message' => 'No brand IDs provided.']];
        }

        $page   = isset($request['page']) ? (int) $request['page'] : 1;
        $result = $this->service->syncPage(SyncRequest::brandsByIds($ids, $page));

        if ($result->success) {
            return ['success' => true, 'data' => $result->toArray()];
        }
        return ['success' => false, 'data' => ['message' => $result->message]];
    }
}
