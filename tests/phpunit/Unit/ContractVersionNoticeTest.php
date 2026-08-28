<?php
/**
 * API Contract Safety — pins the "the DataFlair API moved" info notice.
 *
 * This one is informational, never an error: everything it reports is safe by
 * policy. It must stay silent when there is no news, be visible only to
 * admins, and never claim the site needs action.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Admin;

use DataFlair\Toplists\Admin\Notices\ContractVersionNotice;
use DataFlair\Toplists\Sync\ContractVersion;
use DataFlair\Toplists\Tests\Admin\AdminStubs;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/AdminTestStubs.php';
require_once __DIR__ . '/SyncFunctionStubs.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/ContractVersion.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Notices/ContractVersionNotice.php';

final class ContractVersionNoticeTest extends TestCase
{
    protected function setUp(): void
    {
        AdminStubs::reset();
        \SyncFunctionStubsStore::reset();
    }

    private function render(): string
    {
        ob_start();
        (new ContractVersionNotice())->maybeRender();
        return (string) ob_get_clean();
    }

    /** Seed a recorded reading plus what the admin has already acknowledged. */
    private function seed(string $rev, array $supported, string $seenRev, array $seenSupported): void
    {
        \SyncFunctionStubsStore::$options[ContractVersion::OPTION] = [
            'rev'            => $rev,
            'supported'      => $supported,
            'using'          => 'v1',
            'seen_rev'       => $seenRev,
            'seen_supported' => $seenSupported,
        ];
    }

    public function test_register_hooks_notices_and_the_dismiss_handler(): void
    {
        (new ContractVersionNotice())->register();

        $hooks = array_column(AdminStubs::$actions, 'hook');
        $this->assertContains('admin_notices', $hooks);
        $this->assertContains('admin_init', $hooks);
    }

    public function test_silent_when_there_is_no_news(): void
    {
        $this->seed('1.0.0', ['v1'], '1.0.0', ['v1']);

        $this->assertSame('', $this->render());
    }

    public function test_silent_for_non_admins(): void
    {
        $this->seed('1.1.0', ['v1'], '1.0.0', ['v1']);
        AdminStubs::$currentUserCan = false;

        $this->assertSame('', $this->render());
    }

    public function test_announces_a_moved_revision_as_information_not_an_error(): void
    {
        $this->seed('1.1.0', ['v1'], '1.0.0', ['v1']);

        $html = $this->render();

        $this->assertStringContainsString('notice-info', $html);
        $this->assertStringNotContainsString('notice-error', $html);
        $this->assertStringContainsString('1.0.0', $html);
        $this->assertStringContainsString('1.1.0', $html);
        $this->assertStringContainsString('nothing to do', $html);
        $this->assertStringContainsString('Dismiss', $html);
    }

    public function test_announces_a_newly_available_api_version_and_reassures(): void
    {
        $this->seed('1.0.0', ['v1', 'v2'], '1.0.0', ['v1']);

        $html = $this->render();

        $this->assertStringContainsString('v2', $html);
        $this->assertStringContainsString('nothing changes for you today', $html);
        $this->assertStringNotContainsString('notice-error', $html);
    }

    public function test_dismissing_records_the_reading_as_seen(): void
    {
        $this->seed('1.1.0', ['v1', 'v2'], '1.0.0', ['v1']);
        $_GET = ['dataflair_ack_contract' => '1', '_wpnonce' => 'test-nonce'];

        (new ContractVersionNotice())->maybeAcknowledge();

        $this->assertSame('', $this->render(), 'a dismissed announcement must not come back');
        $_GET = [];
    }

    public function test_dismiss_requires_a_valid_nonce(): void
    {
        $this->seed('1.1.0', ['v1'], '1.0.0', ['v1']);
        $_GET = ['dataflair_ack_contract' => '1', '_wpnonce' => 'forged'];

        (new ContractVersionNotice())->maybeAcknowledge();

        $this->assertNotSame('', $this->render(), 'a forged dismiss must not acknowledge anything');
        $_GET = [];
    }
}
