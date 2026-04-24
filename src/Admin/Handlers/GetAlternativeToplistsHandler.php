<?php
/**
 * List alternative-toplist rows for a given parent toplist.
 * Delegates to AlternativesRepositoryInterface extracted in Phase 2.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Handlers;

use DataFlair\Toplists\Admin\AjaxHandlerInterface;
use DataFlair\Toplists\Database\AlternativesRepositoryInterface;

final class GetAlternativeToplistsHandler implements AjaxHandlerInterface
{
    public function __construct(private AlternativesRepositoryInterface $repo) {}

    public function handle(array $request): array
    {
        $toplist_id = isset($request['toplist_id']) ? (int) $request['toplist_id'] : 0;
        if ($toplist_id <= 0) {
            return ['success' => false, 'data' => ['message' => 'Invalid toplist ID']];
        }

        $rows = $this->repo->findByToplistId($toplist_id);
        usort($rows, fn($a, $b) => strcmp((string) ($a['geo'] ?? ''), (string) ($b['geo'] ?? '')));

        return ['success' => true, 'data' => ['alternatives' => $rows]];
    }
}
