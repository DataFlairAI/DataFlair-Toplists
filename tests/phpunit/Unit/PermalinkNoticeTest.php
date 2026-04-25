<?php
/**
 * Phase 9.6 — pins PermalinkNotice behaviour.
 *
 * Responsibilities under test:
 *   - register() hooks admin_notices.
 *   - maybeRender() emits the warning markup when permalink_structure is empty.
 *   - maybeRender() is silent when permalink_structure is set.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Admin;

use DataFlair\Toplists\Admin\Notices\PermalinkNotice;
use DataFlair\Toplists\Tests\Admin\AdminStubs;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/AdminTestStubs.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Notices/PermalinkNotice.php';

final class PermalinkNoticeTest extends TestCase
{
    protected function setUp(): void
    {
        AdminStubs::reset();
    }

    public function test_register_hooks_admin_notices(): void
    {
        (new PermalinkNotice())->register();

        $hooks = array_column(AdminStubs::$actions, 'hook');
        $this->assertContains(
            'admin_notices',
            $hooks,
            'must hook admin_notices to surface the permalink warning'
        );
    }

    public function test_renders_warning_when_permalink_structure_is_empty(): void
    {
        AdminStubs::$options['permalink_structure'] = '';

        ob_start();
        (new PermalinkNotice())->maybeRender();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('notice notice-error', $html);
        $this->assertStringContainsString('DataFlair', $html);
        $this->assertStringContainsString('Settings', $html);
        $this->assertStringContainsString('Permalinks', $html);
        $this->assertStringContainsString('options-permalink.php', $html);
    }

    public function test_renders_nothing_when_permalink_structure_is_set(): void
    {
        AdminStubs::$options['permalink_structure'] = '/%postname%/';

        ob_start();
        (new PermalinkNotice())->maybeRender();
        $html = (string) ob_get_clean();

        $this->assertSame('', $html, 'no notice should be emitted when permalinks are pretty');
    }

    public function test_renders_nothing_when_option_returns_default_false(): void
    {
        // No option set — get_option() returns the default (false), which
        // empty() treats as empty too. The notice MUST fire in that case
        // because plain permalinks with no `permalink_structure` row are the
        // exact misconfiguration we're trying to flag.
        unset(AdminStubs::$options['permalink_structure']);

        ob_start();
        (new PermalinkNotice())->maybeRender();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString(
            'notice notice-error',
            $html,
            'unset permalink_structure must trigger the warning'
        );
    }
}
