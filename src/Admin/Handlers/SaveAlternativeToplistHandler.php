<?php
/**
 * Upsert an alternative-toplist mapping (toplist_id + geo → alternative_toplist_id).
 * Delegates to AlternativesRepository::upsert() — preserves unique-constraint
 * semantics on (toplist_id, geo) established in Phase 2.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Handlers;

use DataFlair\Toplists\Admin\AjaxHandlerInterface;
use DataFlair\Toplists\Database\AlternativesRepositoryInterface;

final class SaveAlternativeToplistHandler implements AjaxHandlerInterface
{
    public function __construct(private AlternativesRepositoryInterface $repo) {}

    public function handle(array $request): array
    {
        $toplist_id             = isset($request['toplist_id']) ? (int) $request['toplist_id'] : 0;
        $geo                    = isset($request['geo']) ? sanitize_text_field((string) $request['geo']) : '';
        $alternative_toplist_id = isset($request['alternative_toplist_id']) ? (int) $request['alternative_toplist_id'] : 0;

        if ($toplist_id <= 0 || $geo === '' || $alternative_toplist_id <= 0) {
            return ['success' => false, 'data' => ['message' => 'Missing required parameters']];
        }

        $now = current_time('mysql');
        $result = $this->repo->upsert([
            'toplist_id'             => $toplist_id,
            'geo'                    => $geo,
            'alternative_toplist_id' => $alternative_toplist_id,
            'created_at'             => $now,
            'updated_at'             => $now,
        ]);

        if ($result === false) {
            return ['success' => false, 'data' => ['message' => 'Failed to save alternative toplist']];
        }

        return ['success' => true, 'data' => ['message' => 'Alternative toplist saved successfully']];
    }
}
