<?php
/**
 * Phase 9.6 (admin UX redesign) — Delete a set of toplists by api_toplist_id.
 *
 * Input:  { api_toplist_ids: int[] }
 * Output: { success: true, data: { deleted: int } }
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Ajax;

use DataFlair\Toplists\Admin\AjaxHandlerInterface;
use DataFlair\Toplists\Database\ToplistsRepositoryInterface;

final class BulkDeleteToplistsHandler implements AjaxHandlerInterface
{
    public function __construct(private ToplistsRepositoryInterface $repo) {}

    public function handle(array $request): array
    {
        $ids = isset($request['api_toplist_ids']) && is_array($request['api_toplist_ids'])
            ? array_map('intval', $request['api_toplist_ids'])
            : [];
        $ids = array_values(array_filter($ids, static fn(int $id) => $id > 0));

        if (count($ids) === 0) {
            return ['success' => false, 'data' => ['message' => 'No toplist IDs provided.']];
        }

        $deleted = 0;
        foreach ($ids as $id) {
            if ($this->repo->deleteByApiToplistId($id)) {
                $deleted++;
            }
        }

        return ['success' => true, 'data' => ['deleted' => $deleted]];
    }
}
