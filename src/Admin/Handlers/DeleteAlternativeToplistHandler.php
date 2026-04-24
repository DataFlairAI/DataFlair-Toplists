<?php
/**
 * Delete a single alternative-toplist mapping by its primary key.
 * Delegates to AlternativesRepository::deleteById() added in Phase 5.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Handlers;

use DataFlair\Toplists\Admin\AjaxHandlerInterface;
use DataFlair\Toplists\Database\AlternativesRepositoryInterface;

final class DeleteAlternativeToplistHandler implements AjaxHandlerInterface
{
    public function __construct(private AlternativesRepositoryInterface $repo) {}

    public function handle(array $request): array
    {
        $id = isset($request['id']) ? (int) $request['id'] : 0;
        if ($id <= 0) {
            return ['success' => false, 'data' => ['message' => 'Invalid ID']];
        }

        if (!$this->repo->deleteById($id)) {
            return ['success' => false, 'data' => ['message' => 'Failed to delete alternative toplist']];
        }

        return ['success' => true, 'data' => ['message' => 'Alternative toplist deleted successfully']];
    }
}
