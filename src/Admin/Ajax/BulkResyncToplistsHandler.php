<?php
/**
 * Phase 9.6 (admin UX redesign) — Re-sync a subset of toplists.
 *
 * Phase 4 pragmatic implementation: validates token, returns start_batch=true
 * so the JS layer can kick off FetchAllToplistsHandler + SyncToplistsBatchHandler
 * (same path used by the full-sync button). A targeted per-ID sync is a Phase 5
 * enhancement once ToplistSyncServiceInterface gains syncByApiToplistIds().
 *
 * Input:  { api_toplist_ids: int[] }
 * Output: { success: true, data: { start_batch: true, message: string } }
 *       | { success: false, data: { message: string } }
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Ajax;

use DataFlair\Toplists\Admin\AjaxHandlerInterface;

final class BulkResyncToplistsHandler implements AjaxHandlerInterface
{
    public function handle(array $request): array
    {
        $token = trim((string) get_option('dataflair_api_token', ''));
        if ($token === '') {
            return ['success' => false, 'data' => ['message' => 'API token is not configured. Add it in Settings → API Connection.']];
        }

        $ids = isset($request['api_toplist_ids']) && is_array($request['api_toplist_ids'])
            ? array_map('intval', $request['api_toplist_ids'])
            : [];
        $ids = array_filter($ids, static fn(int $id) => $id > 0);

        if (count($ids) === 0) {
            return ['success' => false, 'data' => ['message' => 'No toplist IDs provided.']];
        }

        $count = count($ids);
        return ['success' => true, 'data' => [
            'start_batch' => true,
            'message'     => "Starting batch sync for {$count} toplist(s). Progress will update below.",
        ]];
    }
}
