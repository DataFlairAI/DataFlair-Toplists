<?php
/**
 * Phase 9.6 (admin UX redesign) — pins MenuRegistrar behaviour.
 *
 * Updated for the 5-submenu sitemap introduced in Phase 1:
 *   Dashboard, Toplists, Brands, Tools, Settings.
 * Backward-compat redirects are registered on admin_init.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Admin;

use DataFlair\Toplists\Admin\MenuRegistrar;
use DataFlair\Toplists\Admin\Pages\BrandsPage;
use DataFlair\Toplists\Admin\Pages\DashboardPage;
use DataFlair\Toplists\Admin\Pages\SettingsPage;
use DataFlair\Toplists\Admin\Pages\ToolsPage;
use DataFlair\Toplists\Admin\Pages\ToplistsListPage;
use DataFlair\Toplists\Tests\Admin\AdminStubs;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/AdminTestStubs.php';
require_once __DIR__ . '/MenuRegistrarTestStubs.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Pages/PageInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Pages/DashboardPage.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Pages/ToplistsListPage.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Pages/BrandsPage.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Pages/ToolsPage.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Pages/SettingsPage.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/MenuRegistrar.php';

final class MenuRegistrarTest extends TestCase
{
    protected function setUp(): void
    {
        AdminStubs::reset();
    }

    public function test_register_hooks_admin_menu(): void
    {
        $registrar = $this->makeRegistrar();
        $registrar->register();

        $hooks = array_column(AdminStubs::$actions, 'hook');
        $this->assertContains('admin_menu', $hooks);
        $this->assertContains('admin_init', $hooks);
    }

    public function test_add_admin_menu_registers_parent_and_five_submenus(): void
    {
        $registrar = $this->makeRegistrar();
        $registrar->addAdminMenu();

        $this->assertNotEmpty(AdminStubs::$menuPages);
        // Parent + 5 submenus (Dashboard, Toplists, Brands, Tools, Settings).
        $this->assertCount(5, AdminStubs::$submenuPages, 'Expected 5 submenu pages');
    }

    public function test_parent_menu_slug_is_dataflair_toplists(): void
    {
        $registrar = $this->makeRegistrar();
        $registrar->addAdminMenu();

        $this->assertSame('dataflair-toplists', AdminStubs::$menuPages[0]['menu_slug'] ?? null);
    }

    public function test_five_submenu_slugs_present(): void
    {
        $registrar = $this->makeRegistrar();
        $registrar->addAdminMenu();

        $slugs = array_column(AdminStubs::$submenuPages, 'menu_slug');
        $this->assertContains('dataflair-toplists',      $slugs);
        $this->assertContains('dataflair-toplists-list', $slugs);
        $this->assertContains('dataflair-brands',        $slugs);
        $this->assertContains('dataflair-tools',         $slugs);
        $this->assertContains('dataflair-settings',      $slugs);
    }

    private function makeRegistrar(): MenuRegistrar
    {
        return new MenuRegistrar(
            new DashboardPage(),
            new ToplistsListPage(static fn(string $option) => 'never'),
            new BrandsPage(
                static fn(string $table, string $column): array => [],
                static fn(string $option) => 'never'
            ),
            new ToolsPage(
                static fn() => 'http://api.test',
                new \DataFlair_Toplists()
            ),
            new SettingsPage(
                static fn() => 'http://api.test',
                static fn(string $option) => 'never'
            )
        );
    }
}
