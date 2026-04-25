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
        $isDataflairScreen = in_array($hook, self::ADMIN_HOOKS, true)
            || strpos($hook, 'dataflair') !== false;

        if (!$isDataflairScreen) {
            return;
        }

        // Phase 9.6 (admin UX redesign) — shared chrome on every DataFlair screen.
        $uiCssPath = DATAFLAIR_PLUGIN_DIR . 'assets/admin/admin-ui.css';
        $uiCssVer  = file_exists($uiCssPath) ? (string) filemtime($uiCssPath) : DATAFLAIR_VERSION;
        wp_enqueue_style(
            'dataflair-admin-ui',
            DATAFLAIR_PLUGIN_URL . 'assets/admin/admin-ui.css',
            [],
            $uiCssVer
        );

        $uiJsPath = DATAFLAIR_PLUGIN_DIR . 'assets/admin/admin-ui.js';
        $uiJsVer  = file_exists($uiJsPath) ? (string) filemtime($uiJsPath) : DATAFLAIR_VERSION;
        wp_enqueue_script(
            'dataflair-admin-ui',
            DATAFLAIR_PLUGIN_URL . 'assets/admin/admin-ui.js',
            [],
            $uiJsVer,
            true
        );

        // Legacy assets (Select2 + admin.js) only on the original two screens.
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

        // Phase 9.6 (admin UX redesign) — brands.js only on the Brands page.
        if (strpos($hook, 'dataflair-brands') !== false) {
            $brands_js_path = DATAFLAIR_PLUGIN_DIR . 'assets/admin/brands.js';
            $brands_js_ver  = file_exists($brands_js_path) ? (string) filemtime($brands_js_path) : DATAFLAIR_VERSION;
            wp_enqueue_script(
                'dataflair-brands',
                DATAFLAIR_PLUGIN_URL . 'assets/admin/brands.js',
                ['jquery', 'dataflair-admin-ui'],
                $brands_js_ver,
                true
            );
        }

        // Phase 9.6 (admin UX redesign) — dirty-state.js + color-picker.js on the Settings page.
        if (strpos($hook, 'dataflair-settings') !== false) {
            foreach (['dirty-state', 'color-picker'] as $name) {
                $path = DATAFLAIR_PLUGIN_DIR . "assets/admin/{$name}.js";
                $ver  = file_exists($path) ? (string) filemtime($path) : DATAFLAIR_VERSION;
                wp_enqueue_script(
                    "dataflair-{$name}",
                    DATAFLAIR_PLUGIN_URL . "assets/admin/{$name}.js",
                    ['jquery', 'dataflair-admin-ui'],
                    $ver,
                    true
                );
            }
        }

        // Phase 9.6 (admin UX redesign) — tools.js only on the Tools page.
        if (strpos($hook, 'dataflair-tools') !== false) {
            $tools_js_path = DATAFLAIR_PLUGIN_DIR . 'assets/admin/tools.js';
            $tools_js_ver  = file_exists($tools_js_path) ? (string) filemtime($tools_js_path) : DATAFLAIR_VERSION;
            wp_enqueue_script(
                'dataflair-tools',
                DATAFLAIR_PLUGIN_URL . 'assets/admin/tools.js',
                ['jquery', 'dataflair-admin-ui'],
                $tools_js_ver,
                true
            );
        }
    }
}
