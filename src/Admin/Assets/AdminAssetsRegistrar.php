<?php
/**
 * Admin-side asset registration.
 *
 * Extracted from DataFlair_Toplists::enqueue_admin_scripts() in Phase 5. Loads
 * Select2 for the toplists + brands pages and the plugin's own admin.js with
 * a filemtime-based cache-bust. Localises the `dataflairAdmin` global with
 * the nonces the admin JS uses to call every AJAX endpoint.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Assets;

final class AdminAssetsRegistrar
{
    /** Hook screens where DataFlair admin assets should load. */
    private const ADMIN_HOOKS = [
        'toplevel_page_dataflair-toplists',
        'dataflair_page_dataflair-brands',
    ];

    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    /**
     * @param string $hook Current admin screen's hook suffix.
     */
    public function enqueue(string $hook): void
    {
        if (!in_array($hook, self::ADMIN_HOOKS, true)) {
            return;
        }

        wp_enqueue_style(
            'select2',
            'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
            [],
            '4.1.0'
        );
        wp_enqueue_script(
            'select2',
            'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
            ['jquery'],
            '4.1.0',
            true
        );

        $admin_js_path = DATAFLAIR_PLUGIN_DIR . 'assets/admin.js';
        $admin_js_ver  = file_exists($admin_js_path) ? (string) filemtime($admin_js_path) : DATAFLAIR_VERSION;

        wp_enqueue_script(
            'dataflair-admin',
            DATAFLAIR_PLUGIN_URL . 'assets/admin.js',
            ['jquery'],
            $admin_js_ver,
            true
        );

        wp_localize_script('dataflair-admin', 'dataflairAdmin', [
            'ajaxUrl'                => admin_url('admin-ajax.php'),
            'nonce'                  => wp_create_nonce('dataflair_save_settings'),
            'fetchNonce'             => wp_create_nonce('dataflair_fetch_all_toplists'),
            'syncToplistsBatchNonce' => wp_create_nonce('dataflair_sync_toplists_batch'),
            'fetchBrandsNonce'       => wp_create_nonce('dataflair_fetch_all_brands'),
            'syncBrandsBatchNonce'   => wp_create_nonce('dataflair_sync_brands_batch'),
        ]);
    }
}
