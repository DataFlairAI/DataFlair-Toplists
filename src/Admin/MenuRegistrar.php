<?php
/**
 * Phase 9.6 (admin UX redesign) — Admin menu registration.
 *
 * Five-submenu sitemap:
 *   dataflair-toplists  → DashboardPage  (parent slug kept for back-compat)
 *   dataflair-toplists  → ToplistsListPage (the actual "Toplists" submenu)
 *   dataflair-brands    → BrandsPage
 *   dataflair-tools     → ToolsPage
 *   dataflair-settings  → SettingsPage
 *
 * Backward-compat redirects (via admin_init):
 *   ?page=dataflair-toplists&tab=api           → dataflair-settings&tab=api_connection
 *   ?page=dataflair-toplists&tab=customizations → dataflair-settings&tab=customizations
 *   ?page=dataflair-toplists&tab=api_preview    → dataflair-tools&tab=api_preview
 *
 * Tests page: WP_DEBUG gate dropped — always registered under Tools.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin;

use DataFlair\Toplists\Admin\Pages\BrandsPage;
use DataFlair\Toplists\Admin\Pages\DashboardPage;
use DataFlair\Toplists\Admin\Pages\SettingsPage;
use DataFlair\Toplists\Admin\Pages\ToolsPage;
use DataFlair\Toplists\Admin\Pages\ToplistsListPage;

final class MenuRegistrar
{
    public function __construct(
        private DashboardPage $dashboardPage,
        private ToplistsListPage $toplistsPage,
        private BrandsPage $brandsPage,
        private ToolsPage $toolsPage,
        private SettingsPage $settingsPage
    ) {}

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addAdminMenu']);
        add_action('admin_init', [$this, 'addBackwardCompatRedirects']);
    }

    public function addAdminMenu(): void
    {
        // Parent menu + Dashboard submenu (same slug so parent highlights on dashboard).
        add_menu_page(
            'DataFlair',
            'DataFlair',
            'manage_options',
            'dataflair-toplists',
            [$this->dashboardPage, 'render'],
            'dashicons-list-view',
            30
        );

        add_submenu_page(
            'dataflair-toplists',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'dataflair-toplists',
            [$this->dashboardPage, 'render']
        );

        add_submenu_page(
            'dataflair-toplists',
            'Toplists',
            'Toplists',
            'manage_options',
            'dataflair-toplists-list',
            [$this->toplistsPage, 'render']
        );

        add_submenu_page(
            'dataflair-toplists',
            'Brands',
            'Brands',
            'manage_options',
            'dataflair-brands',
            [$this->brandsPage, 'render']
        );

        add_submenu_page(
            'dataflair-toplists',
            'Tools',
            'Tools',
            'manage_options',
            'dataflair-tools',
            [$this->toolsPage, 'render']
        );

        add_submenu_page(
            'dataflair-toplists',
            'Settings',
            'Settings',
            'manage_options',
            'dataflair-settings',
            [$this->settingsPage, 'render']
        );
    }

    /**
     * 302 redirects for old `?page=dataflair-toplists&tab=*` deep-links.
     * Fires on admin_init before any output so the redirect is clean.
     */
    public function addBackwardCompatRedirects(): void
    {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        $page = isset($_GET['page']) ? (string) $_GET['page'] : '';
        $tab  = isset($_GET['tab'])  ? (string) $_GET['tab']  : '';

        if ($page !== 'dataflair-toplists' || $tab === '') {
            return;
        }

        $map = [
            'api'            => admin_url('admin.php?page=dataflair-settings&tab=api_connection'),
            'customizations' => admin_url('admin.php?page=dataflair-settings&tab=customizations'),
            'api_preview'    => admin_url('admin.php?page=dataflair-tools&tab=api_preview'),
        ];

        if (isset($map[$tab])) {
            wp_safe_redirect($map[$tab], 302);
            exit;
        }
    }
}
