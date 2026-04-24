<?php
/**
 * Controller for GET /wp-json/dataflair/v1/toplists.
 *
 * Returns a lean `{value, label}` options list used by the block editor and
 * admin pickers. Value is the upstream toplist ID (string), label is the
 * toplist name plus a slug/ID suffix for disambiguation.
 *
 * Phase 6 — extracted from god-class `get_toplists_rest()`.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Rest\Controllers;

use DataFlair\Toplists\Database\ToplistsRepositoryInterface;
use DataFlair\Toplists\Logging\LoggerInterface;

final class ToplistsController
{
    public function __construct(
        private ToplistsRepositoryInterface $repo,
        private LoggerInterface $logger
    ) {}

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function list()
    {
        try {
            $rows    = $this->repo->listAllForOptions();
            $options = [];
            foreach ($rows as $row) {
                $api_id = (string) ($row['api_toplist_id'] ?? '');
                $name   = (string) ($row['name'] ?? '');
                $slug   = (string) ($row['slug'] ?? '');
                $suffix = $slug !== ''
                    ? ' [' . $slug . ']'
                    : ' (ID: ' . $api_id . ')';
                $options[] = [
                    'value' => $api_id,
                    'label' => $name . $suffix,
                ];
            }

            return rest_ensure_response($options);
        } catch (\Throwable $e) {
            $this->logger->error('rest.toplists.list.failed', ['error' => $e->getMessage()]);
            return new \WP_Error('exception', $e->getMessage(), ['status' => 500]);
        }
    }
}
