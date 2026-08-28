<?php
/**
 * API Contract Safety P2 — pins ContractMismatchNotice behaviour.
 *
 * Responsibilities under test:
 *   - register() hooks admin_notices.
 *   - maybeRender() is silent while no mismatch is recorded.
 *   - maybeRender() renders the message, the stale-data reassurance, and the
 *     minimum plugin version when one is recorded.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Admin;

use DataFlair\Toplists\Admin\Notices\ContractMismatchNotice;
use DataFlair\Toplists\Sync\ContractMismatch;
use DataFlair\Toplists\Tests\Admin\AdminStubs;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/AdminTestStubs.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/ContractMismatch.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Notices/ContractMismatchNotice.php';

final class ContractMismatchNoticeTest extends TestCase
{
    protected function setUp(): void
    {
        AdminStubs::reset();
    }

    public function test_register_hooks_admin_notices(): void
    {
        (new ContractMismatchNotice())->register();

        $hooks = array_column(AdminStubs::$actions, 'hook');
        $this->assertContains('admin_notices', $hooks);
    }

    public function test_silent_when_no_mismatch_recorded(): void
    {
        ob_start();
        (new ContractMismatchNotice())->maybeRender();
        $this->assertSame('', ob_get_clean());
    }

    public function test_renders_message_and_min_version_when_recorded(): void
    {
        AdminStubs::$options[ContractMismatch::OPTION] = [
            'message'            => 'Plugin 1.5.0 is below the minimum supported version.',
            'min_plugin_version' => '2.5.0',
            'source'             => 'toplists',
        ];

        ob_start();
        (new ContractMismatchNotice())->maybeRender();
        $html = ob_get_clean();

        $this->assertStringContainsString('DataFlair sync is paused', $html);
        $this->assertStringContainsString('below the minimum supported version', $html);
        $this->assertStringContainsString('last synced data', $html);
        $this->assertStringContainsString('2.5.0', $html);
        $this->assertStringContainsString('plugins.php', $html);
    }

    public function test_omits_update_hint_when_no_min_version(): void
    {
        AdminStubs::$options[ContractMismatch::OPTION] = [
            'message'            => 'Contract mismatch.',
            'min_plugin_version' => '',
        ];

        ob_start();
        (new ContractMismatchNotice())->maybeRender();
        $html = ob_get_clean();

        $this->assertStringContainsString('Contract mismatch.', $html);
        $this->assertStringNotContainsString('plugins.php', $html);
    }

    public function test_escapes_untrusted_backend_message(): void
    {
        AdminStubs::$options[ContractMismatch::OPTION] = [
            'message'            => '<script>alert(1)</script>',
            'min_plugin_version' => '',
        ];

        ob_start();
        (new ContractMismatchNotice())->maybeRender();
        $html = ob_get_clean();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
