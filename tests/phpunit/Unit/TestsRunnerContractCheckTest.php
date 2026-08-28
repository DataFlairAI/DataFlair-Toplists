<?php
/**
 * API Contract Safety P5 — pins the on-demand contract_check diagnostic.
 *
 * Covers the no-HTTP paths only (recorded mismatch, missing config); the
 * live-HTTP happy path is exercised end-to-end against strike-odds.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Admin\Tools;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DataFlair\Toplists\Admin\Pages\Tools\TestsRunner;
use DataFlair\Toplists\Sync\ContractMismatch;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/ContractMismatch.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/ContractCanary.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Pages/Tools/TestsRunner.php';

final class TestsRunnerContractCheckTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->options = [];

        Functions\when('get_option')->alias(function ($key, $default = false) {
            return $this->options[$key] ?? $default;
        });
        Functions\when('update_option')->alias(function ($key, $value) {
            $this->options[$key] = $value;
            return true;
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_contract_check_is_registered(): void
    {
        $this->assertArrayHasKey('contract_check', TestsRunner::registry());
    }

    public function test_reports_fail_while_sync_is_paused_on_recorded_mismatch(): void
    {
        $this->options[ContractMismatch::OPTION] = [
            'message'            => 'Plugin 1.5.0 is below the minimum supported version.',
            'min_plugin_version' => '2.5.0',
            'source'             => 'toplists',
        ];

        $result = (new TestsRunner())->run('contract_check');

        $this->assertSame('fail', $result['status']);
        $this->assertStringContainsString('paused', $result['message']);
    }

    public function test_warns_without_hitting_network_when_unconfigured(): void
    {
        $result = (new TestsRunner())->run('contract_check');

        $this->assertSame('warn', $result['status']);
        $this->assertStringContainsString('skipped', $result['message']);
    }
}
