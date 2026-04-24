<?php
/**
 * Fetch-all bootstrap endpoint for brands. Counterpart to
 * FetchAllToplistsHandler. Returns `{ message, start_batch: true }` so the
 * admin JS starts the batched sync.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Handlers;

use DataFlair\Toplists\Admin\AjaxHandlerInterface;

final class FetchAllBrandsHandler implements AjaxHandlerInterface
{
    public function handle(array $request): array
    {
        $token = trim((string) get_option('dataflair_api_token'));
        if ($token === '') {
            return [
                'success' => false,
                'data'    => ['message' => 'API token not configured. Please set your API token first.'],
            ];
        }

        return [
            'success' => true,
            'data'    => ['message' => 'Starting batch sync...', 'start_batch' => true],
        ];
    }
}
