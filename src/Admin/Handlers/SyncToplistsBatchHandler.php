<?php
/**
 * Sync one page of toplists. Delegates to ToplistSyncServiceInterface
 * extracted in Phase 3 — all Phase 0B H4/H7/H8 invariants and Phase 1
 * telemetry preserved inside the service.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Handlers;

use DataFlair\Toplists\Admin\AjaxHandlerInterface;
use DataFlair\Toplists\Sync\SyncRequest;
use DataFlair\Toplists\Sync\ToplistSyncServiceInterface;

final class SyncToplistsBatchHandler implements AjaxHandlerInterface
{
    public function __construct(private ToplistSyncServiceInterface $service) {}

    public function handle(array $request): array
    {
        $token = trim((string) get_option('dataflair_api_token'));
        if ($token === '') {
            return [
                'success' => false,
                'data'    => ['message' => 'API token not configured. Please set your API token first.'],
            ];
        }

        $page   = isset($request['page']) ? (int) $request['page'] : 1;
        $result = $this->service->syncPage(SyncRequest::toplists($page));

        if ($result->success) {
            return ['success' => true, 'data' => $result->toArray()];
        }
        return ['success' => false, 'data' => ['message' => $result->message]];
    }
}
