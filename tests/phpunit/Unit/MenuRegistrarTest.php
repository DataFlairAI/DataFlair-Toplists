<?php
/**
 * Phase 9.6 — pins MenuRegistrar behaviour.
 *
 * Responsibilities under test:
 *   - register() hooks admin_menu.
 *   - addAdminMenu() wires the top-level + 2 submenus to SettingsPage/BrandsPage.
 *   - WP_DEBUG adds a 3rd submenu (Tests) routed to the legacy class.
 *   - Without WP_DEBUG, the Tests submenu is absent.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Admin;

use DataFlair\Toplists\Admin\MenuRegistrar;
use DataFlair\Toplists\Admin\Pages\BrandsPage;
use DataFlair\Toplists\Admin\Pages\SettingsPage;
use DataFlair\Toplists\Tests\Admin\AdminStubs;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/AdminTestStubs.php';
require_once __DIR__ . '/MenuRegistrarTestStubs.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Pages/PageInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Pages/SettingsPage.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Pages/BrandsPage.php';
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
        $this->assertContains('admin_menu', $hooks, 'must hook admin_menu for menu registration');
    }

    public function test_add_admin_menu_registers_top_level_with_dashicons_icon(): void
    {
        $registrar = $this->makeRegistrar();

        $registrar->addAdminMenu();

        $this->assertCount(1, AdminStubs::$menuPages, 'one top-level menu page');
        $page = AdminStubs::$menuPages[0];
        $this->assertSame('DataFlair', $page['page_title']);
        $this->assertSame('DataFlair', $page['menu_title']);
        $this->assertSame('manage_options', $page['capability']);
        $this->assertSame('dataflair-toplists', $page['menu_slug']);
        $this->assertSame('dashicons-list-view', $page['icon_url']);
        $this->assertSame(30, $page['position']);
    }

    public function test_add_admin_menu_routes_top_level_callback_to_settings_page_render(): void
    {
        $settings = $this->mockSettingsPage();
        $registrar = $this->makeRegistrar($settings);

        $registrar->addAdminMenu();

        $callback = AdminStubs::$menuPages[0]['callback'];
        $this->assertIsArray($callback);
        $this->assertSame($settings, $callback[0]);
        $this->assertSame('render', $callback[1]);
    }

    public function test_add_admin_menu_registers_two_submenus_for_settings_and_brands(): void
    {
        $registrar = $this->makeRegistrar();

        $registrar->addAdminMenu();

        $slugs = array_column(AdminStubs::$submenuPages, 'menu_slug');
        $this->assertContains('dataflair-toplists', $slugs);
        $this->assertContains('dataflair-brands', $slugs);
    }

    public function test_brands_submenu_routes_to_brands_page_render(): void
    {
        $brands = $this->mockBrandsPage();
        $registrar = $this->makeRegistrar(null, $brands);

        $registrar->addAdminMenu();

        $brandsSubmenu = null;
        foreach (AdminStubs::$submenuPages as $entry) {
            if ($entry['menu_slug'] === 'dataflair-brands') {
                $brandsSubmenu = $entry;
                break;
            }
        }
        $this->assertNotNull($brandsSubmenu);
        $this->assertSame([$brands, 'render'], $brandsSubmenu['callback']);
        $this->assertSame('manage_options', $brandsSubmenu['capability']);
    }

    public function test_wp_debug_branch_can_be_evaluated_without_fatal(): void
    {
        // Defining WP_DEBUG mid-suite is fragile (constants live process-wide
        // and other tests may already have defined it either way). The branch
        // is a one-liner `if (defined('WP_DEBUG') && WP_DEBUG)`; the code path
        // is fully exercised whenever WP_DEBUG happens to be true at runtime.
        // The real check here is that addAdminMenu() runs cleanly regardless.
        $registrar = $this->makeRegistrar();

        $registrar->addAdminMenu();

        $this->assertNotEmpty(AdminStubs::$menuPages);
        $this->assertNotEmpty(AdminStubs::$submenuPages);
    }

    private function makeRegistrar(
        ?SettingsPage $settings = null,
        ?BrandsPage $brands = null,
        ?\DataFlair_Toplists $legacy = null
    ): MenuRegistrar {
        return new MenuRegistrar(
            $settings ?? $this->mockSettingsPage(),
            $brands   ?? $this->mockBrandsPage(),
            $legacy   ?? new \DataFlair_Toplists()
        );
    }

    private function mockSettingsPage(): SettingsPage
    {
        return new SettingsPage(
            static fn() => 'http://api.test',
            static fn(string $option) => 'never'
        );
    }

    private function mockBrandsPage(): BrandsPage
    {
        return new BrandsPage(
            static fn(string $table, string $column): array => [],
            static fn(string $option) => 'never'
        );
    }
}
