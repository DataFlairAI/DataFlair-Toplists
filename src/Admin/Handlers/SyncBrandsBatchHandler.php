<?php
/**
 * Sync one page of brands. Delegates to BrandSyncServiceInterface extracted
 * in Phase 3 — all Phase 0A/0B invariants (size caps, budget, logo hook)
 * and Phase 1 telemetry preserved inside the service.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Handlers;

use DataFlair\Toplists\Admin\AjaxHandlerInterface;
use DataFlair\Toplists\Sync\BrandSyncServiceInterface;
use DataFlair\Toplists\Sync\SyncRequest;

final class SyncBrandsBatchHandler implements AjaxHandlerInterface
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

        $page   = isset($request['page']) ? (int) $request['page'] : 1;
        $result = $this->service->syncPage(SyncRequest::brands($page));

        if ($result->success) {
            return ['success' => true, 'data' => $result->toArray()];
        }
        return ['success' => false, 'data' => ['message' => $result->message]];
    }
}
