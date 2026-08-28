<?php
/**
 * API Contract Safety P2 — pins ContractMismatchNotice behaviour.
 *
 * Responsibilities under test:
 *   - register() hooks admin_notices.
 *   - Non-admins never trigger the option lookup or any output.
 *   - Silent while no mismatch is recorded.
 *   - Renders one notice per paused stream with escaped, capped content.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Admin;

use DataFlair\Toplists\Admin\Notices\ContractMismatchNotice;
use DataFlair\Toplists\Sync\ContractMismatch;
use DataFlair\Toplists\Tests\Admin\AdminStubs;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/AdminTestStubs.php';
require_once __DIR__ . '/SyncFunctionStubs.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/ContractMismatch.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Notices/ContractMismatchNotice.php';

final class ContractMismatchNoticeTest extends TestCase
{
    protected function setUp(): void
    {
        AdminStubs::reset();
        \SyncFunctionStubsStore::reset();
    }

    private function render(): string
    {
        ob_start();
        (new ContractMismatchNotice())->maybeRender();
        return (string) ob_get_clean();
    }

    public function test_register_hooks_admin_notices(): void
    {
        (new ContractMismatchNotice())->register();

        $hooks = array_column(AdminStubs::$actions, 'hook');
        $this->assertContains('admin_notices', $hooks);
    }

    public function test_silent_when_no_mismatch_recorded(): void
    {
        $this->assertSame('', $this->render());
    }

    public function test_silent_for_non_admins_even_with_mismatch_recorded(): void
    {
        \SyncFunctionStubsStore::$options[ContractMismatch::OPTION] = [
            'toplists' => ['message' => 'Mismatch.', 'min_plugin_version' => ''],
        ];
        AdminStubs::$currentUserCan = false;

        $this->assertSame('', $this->render());
    }

    public function test_renders_message_and_min_version_when_recorded(): void
    {
        \SyncFunctionStubsStore::$options[ContractMismatch::OPTION] = [
            'toplists' => [
                'message'            => 'Plugin 1.5.0 is below the minimum supported version.',
                'min_plugin_version' => '2.5.0',
                'source'             => 'toplists',
            ],
        ];

        $html = $this->render();

        $this->assertStringContainsString('DataFlair sync is paused', $html);
        $this->assertStringContainsString('below the minimum supported version', $html);
        $this->assertStringContainsString('last synced data', $html);
        $this->assertStringContainsString('2.5.0', $html);
        $this->assertStringContainsString('plugins.php', $html);
    }

    public function test_renders_one_notice_per_paused_stream(): void
    {
        \SyncFunctionStubsStore::$options[ContractMismatch::OPTION] = [
            'toplists' => ['message' => 'Toplists mismatch.', 'min_plugin_version' => ''],
            'brands'   => ['message' => 'Brands mismatch.', 'min_plugin_version' => ''],
        ];

        $html = $this->render();

        $this->assertStringContainsString('Toplists mismatch.', $html);
        $this->assertStringContainsString('Brands mismatch.', $html);
        $this->assertSame(2, substr_count($html, 'notice-error'));
    }

    public function test_omits_update_hint_when_no_min_version(): void
    {
        \SyncFunctionStubsStore::$options[ContractMismatch::OPTION] = [
            'toplists' => ['message' => 'Contract mismatch.', 'min_plugin_version' => ''],
        ];

        $html = $this->render();

        $this->assertStringContainsString('Contract mismatch.', $html);
        $this->assertStringNotContainsString('plugins.php', $html);
    }

    public function test_escapes_untrusted_backend_message(): void
    {
        \SyncFunctionStubsStore::$options[ContractMismatch::OPTION] = [
            'toplists' => ['message' => '<script>alert(1)</script>', 'min_plugin_version' => ''],
        ];

        $html = $this->render();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
