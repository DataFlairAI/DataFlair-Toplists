<?php
/**
 * Sync one page of toplists. Delegates to ToplistSyncServiceInterface
 * extracted in Phase 3 — all Phase 0B H4/H7/H8 invariants and Phase 1
 * telemetry preserved inside the service.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Ajax;

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
        $t0     = microtime(true);
        $result = $this->service->syncPage(SyncRequest::toplists($page));
        $ms     = (int) round((microtime(true) - $t0) * 1000);

        // Mirror the CLI sync output to PHP error_log so the user can tail
        // wp-content/debug.log while clicking "Fetch All Toplists from API".
        $line = sprintf(
            '[DataFlair] FetchAllToplists page=%d/%d synced=%d errors=%d elapsed_ms=%d partial=%d%s',
            $page,
            $result->lastPage,
            $result->synced,
            $result->errors,
            $ms,
            $result->partial ? 1 : 0,
            $result->success ? '' : ' FAILED: ' . $result->message
        );
        error_log($line);

        if ($result->success) {
            return ['success' => true, 'data' => $result->toArray()];
        }
        return ['success' => false, 'data' => ['message' => $result->message]];
    }
}
