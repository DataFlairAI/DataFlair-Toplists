<?php
/**
 * Phase 9.6 (admin UX redesign) — Re-sync a subset of toplists by their API IDs.
 *
 * Calls ToplistSyncServiceInterface::syncByApiToplistIds() for an immediate
 * per-ID sync without touching the full-sync batch loop.
 *
 * Input:  { api_toplist_ids: int[] }
 * Output: { success: true,  data: { synced: int, errors: int, message: string } }
 *       | { success: false, data: { message: string } }
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Ajax;

use DataFlair\Toplists\Admin\AjaxHandlerInterface;
use DataFlair\Toplists\Sync\ToplistSyncServiceInterface;

final class BulkResyncToplistsHandler implements AjaxHandlerInterface
{
    public function __construct(
        private readonly ToplistSyncServiceInterface $sync
    ) {}

    public function handle(array $request): array
    {
        $token = trim((string) get_option('dataflair_api_token', ''));
        if ($token === '') {
            return ['success' => false, 'data' => ['message' => 'API token is not configured. Add it in Settings → API Connection.']];
        }

        $ids = isset($request['api_toplist_ids']) && is_array($request['api_toplist_ids'])
            ? array_map('intval', $request['api_toplist_ids'])
            : [];
        $ids = array_values(array_filter($ids, static fn(int $id) => $id > 0));

        if (count($ids) === 0) {
            return ['success' => false, 'data' => ['message' => 'No toplist IDs provided.']];
        }

        $result = $this->sync->syncByApiToplistIds($ids);
        $data   = $result->toArray();
        $count  = count($ids);
        $msg    = "Re-synced {$data['synced']} of {$count} toplist(s)."
            . ($data['errors'] > 0 ? " {$data['errors']} error(s)." : '');

        return ['success' => true, 'data' => [
            'synced'  => $data['synced'],
            'errors'  => $data['errors'],
            'message' => $msg,
        ]];
    }
}
