<?php
/**
 * Phase 9.6 (admin UX redesign) — Return the stored raw data blob for a toplist.
 *
 * Used by the Raw JSON tab inside the accordion. Returns the decoded+re-encoded
 * JSON so it is always UTF-8 clean and consistently formatted.
 *
 * Input:  { api_toplist_id: int }
 * Output: { success: true, data: { api_toplist_id: int, json: string } }
 *       | { success: false, data: { message: string } }
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Ajax;

use DataFlair\Toplists\Admin\AjaxHandlerInterface;
use DataFlair\Toplists\Database\ToplistsRepositoryInterface;

final class ToplistRawJsonHandler implements AjaxHandlerInterface
{
    public function __construct(private ToplistsRepositoryInterface $repo) {}

    public function handle(array $request): array
    {
        $id = isset($request['api_toplist_id']) ? (int) $request['api_toplist_id'] : 0;
        if ($id <= 0) {
            return ['success' => false, 'data' => ['message' => 'Invalid api_toplist_id.']];
        }

        $data = $this->repo->findRawDataByApiToplistId($id);
        if ($data === null) {
            return ['success' => false, 'data' => ['message' => 'Toplist not found.']];
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return ['success' => false, 'data' => ['message' => 'Failed to encode JSON.']];
        }

        return ['success' => true, 'data' => [
            'api_toplist_id' => $id,
            'json'           => $json,
        ]];
    }
}
