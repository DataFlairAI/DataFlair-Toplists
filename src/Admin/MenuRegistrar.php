<?php
/**
 * Phase 9.6 — Admin menu registration.
 *
 * Owns the `admin_menu` hook + the `add_menu_page` / `add_submenu_page`
 * calls that mount DataFlair under the WP admin sidebar. Extracted from
 * `DataFlair_Toplists::add_admin_menu()`. The page-callback closures route
 * `render()` calls through the namespaced `Admin\Pages\*` classes.
 *
 * Single responsibility: tell WordPress where DataFlair's admin pages live.
 * No HTML, no settings registration — those have their own classes.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin;

use DataFlair\Toplists\Admin\Pages\BrandsPage;
use DataFlair\Toplists\Admin\Pages\SettingsPage;

final class MenuRegistrar
{
    public function __construct(
        private SettingsPage $settingsPage,
        private BrandsPage $brandsPage,
        private \DataFlair_Toplists $legacy
    ) {}

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addAdminMenu']);
    }

    public function addAdminMenu(): void
    {
        add_menu_page(
            'DataFlair',
            'DataFlair',
            'manage_options',
            'dataflair-toplists',
            [$this->settingsPage, 'render'],
            'dashicons-list-view',
            30
        );

        add_submenu_page(
            'dataflair-toplists',
            'Toplists',
            'Toplists',
            'manage_options',
            'dataflair-toplists',
            [$this->settingsPage, 'render']
        );

        add_submenu_page(
            'dataflair-toplists',
            'Brands',
            'Brands',
            'manage_options',
            'dataflair-brands',
            [$this->brandsPage, 'render']
        );

        if (defined('WP_DEBUG') && WP_DEBUG) {
            add_submenu_page(
                'dataflair-toplists',
                'Tests',
                'Tests',
                'manage_options',
                'dataflair-tests',
                [$this->legacy, 'tests_page']
            );
        }
    }
}
