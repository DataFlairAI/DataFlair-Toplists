<?php
/**
 * Phase 9.6 — pins SettingsRegistrar behaviour.
 *
 * Responsibilities under test:
 *   - register() hooks admin_init.
 *   - registerSettings() registers exactly the documented option list under
 *     the `dataflair_settings` group.
 *   - The duplicated `dataflair_api_base_url` registration (bug-compatible
 *     with the original god-class) is preserved.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Admin;

use DataFlair\Toplists\Admin\SettingsRegistrar;
use DataFlair\Toplists\Tests\Admin\AdminStubs;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/AdminTestStubs.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/SettingsRegistrar.php';

final class SettingsRegistrarTest extends TestCase
{
    protected function setUp(): void
    {
        AdminStubs::reset();
    }

    public function test_register_hooks_admin_init(): void
    {
        $registrar = new SettingsRegistrar();

        $registrar->register();

        $hooks = array_column(AdminStubs::$actions, 'hook');
        $this->assertContains('admin_init', $hooks, 'must hook admin_init for register_setting');
    }

    public function test_register_settings_registers_documented_options_under_dataflair_settings_group(): void
    {
        $registrar = new SettingsRegistrar();

        $registrar->registerSettings();

        $groups = array_column(AdminStubs::$registeredSettings, 'group');
        $this->assertNotEmpty($groups);
        foreach ($groups as $group) {
            $this->assertSame(
                'dataflair_settings',
                $group,
                'every option must be registered under the dataflair_settings group'
            );
        }

        $options = array_column(AdminStubs::$registeredSettings, 'option');
        $expected = [
            'dataflair_api_token',
            'dataflair_api_base_url',
            'dataflair_api_endpoints',
            'dataflair_http_auth_user',
            'dataflair_http_auth_pass',
            'dataflair_ribbon_bg_color',
            'dataflair_ribbon_text_color',
            'dataflair_cta_bg_color',
            'dataflair_cta_text_color',
        ];
        foreach ($expected as $name) {
            $this->assertContains($name, $options, "must register `$name`");
        }
    }

    public function test_api_base_url_is_registered_twice_for_byte_parity(): void
    {
        $registrar = new SettingsRegistrar();

        $registrar->registerSettings();

        $apiBaseHits = array_filter(
            AdminStubs::$registeredSettings,
            static fn(array $entry) => $entry['option'] === 'dataflair_api_base_url'
        );
        $this->assertCount(
            2,
            $apiBaseHits,
            'The original god-class double-registered dataflair_api_base_url; '
                . 'preserving the duplicate keeps WP Settings API behaviour byte-identical.'
        );
    }

    public function test_default_args_pass_null_sanitize_callback_and_default(): void
    {
        $registrar = new SettingsRegistrar();

        $registrar->registerSettings();

        $first = AdminStubs::$registeredSettings[0]['args'];
        $this->assertArrayHasKey('sanitize_callback', $first);
        $this->assertNull($first['sanitize_callback']);
        $this->assertArrayHasKey('default', $first);
        $this->assertNull($first['default']);
    }
}
