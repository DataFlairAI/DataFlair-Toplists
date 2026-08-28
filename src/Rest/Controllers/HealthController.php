<?php
/**
 * Controller for GET /wp-json/dataflair/v1/health.
 *
 * Returns `{status, toplists, plugin_ver, db_error}` for operational checks.
 * Restricted to `manage_options` so the count is not a public signal.
 *
 * Phase 6 — extracted from god-class inline closure.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Rest\Controllers;

use DataFlair\Toplists\Database\ToplistsRepositoryInterface;

final class HealthController
{
    public function __construct(private ToplistsRepositoryInterface $repo) {}

    /**
     * @return \WP_REST_Response
     */
    public function status()
    {
        global $wpdb;

        $mismatches = \DataFlair\Toplists\Sync\ContractMismatch::entries();

        return rest_ensure_response([
            'status'     => 'ok',
            'toplists'   => $this->repo->countAll(),
            'plugin_ver' => DATAFLAIR_VERSION,
            'db_error'   => ($wpdb instanceof \wpdb && !empty($wpdb->last_error)) ? $wpdb->last_error : null,
            // Non-null while any sync stream is paused on a contract mismatch
            // (keyed by stream) — lets authenticated monitoring (this route
            // requires manage_options) catch it without scraping wp-admin.
            'contract_mismatch' => $mismatches !== [] ? $mismatches : null,
        ]);
    }
}
