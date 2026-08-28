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
        $version    = \DataFlair\Toplists\Sync\ContractVersion::profile();

        return rest_ensure_response([
            'status'     => 'ok',
            'toplists'   => $this->repo->countAll(),
            'plugin_ver' => DATAFLAIR_VERSION,
            'db_error'   => ($wpdb instanceof \wpdb && !empty($wpdb->last_error)) ? $wpdb->last_error : null,
            // Integration profile: how THIS site uses the plugin. Support
            // starts from facts instead of asking the tenant to describe
            // their setup, and it stays accurate as their setup changes.
            'integration' => [
                'geo_targeting'      => get_option('dataflair_geo_targeting_enabled', '1') !== '0',
                'api_contract'       => $version['using'],
                'api_contract_rev'   => $version['rev'],
                'api_supported'      => $version['supported'],
                'last_toplists_sync' => (int) get_option('dataflair_last_toplists_sync', 0),
                'last_brands_sync'   => (int) get_option('dataflair_last_brands_sync', 0),
            ],
            // Non-null while any sync stream is paused on a contract mismatch
            // (keyed by stream) — lets authenticated monitoring (this route
            // requires manage_options) catch it without scraping wp-admin.
            'contract_mismatch' => $mismatches !== [] ? $mismatches : null,
        ]);
    }
}
