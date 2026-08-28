<?php
/**
 * API Contract Safety P5 — pins the on-demand contract_check diagnostic.
 *
 * The check must always probe live (an operator uses it to ask "does the
 * contract work NOW?", including while sync is paused), resolve its base URL
 * through ApiBaseUrlDetector like real sync does, and report a recorded
 * mismatch as a warning alongside a passing live probe rather than hiding
 * the recovery.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Admin\Tools;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use DataFlair\Toplists\Admin\Pages\Tools\TestsRunner;
use DataFlair\Toplists\Logging\LoggerFactory;
use DataFlair\Toplists\Logging\NullLogger;
use DataFlair\Toplists\Sync\ContractMismatch;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/NullLogger.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerFactory.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/ContractMismatch.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/ContractCanary.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Pages/Tools/TestsRunner.php';
require_once DATAFLAIR_PLUGIN_DIR . 'tests/phpunit/WpErrorStub.php';
require_once __DIR__ . '/SyncFunctionStubs.php';

final class TestsRunnerContractCheckTest extends TestCase
{
    /** @var array<string, mixed> Options read via the GLOBAL namespace (TestsRunner, ApiClient, detector). */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        \SyncFunctionStubsStore::reset();
        LoggerFactory::reset();
        $this->options = [];

        Filters\expectApplied('dataflair_logger')
            ->zeroOrMoreTimes()->andReturnUsing(static fn() => new NullLogger());
        Filters\expectApplied('dataflair_logger_level')
            ->zeroOrMoreTimes()->andReturnUsing(static fn($default) => $default);
        // Force the wp_remote_get transport so the stub below is exercised.
        Filters\expectApplied('dataflair_use_persistent_curl')
            ->zeroOrMoreTimes()->andReturn(false);

        Functions\when('get_option')->alias(function ($key, $default = false) {
            return $this->options[$key] ?? $default;
        });
        Functions\when('update_option')->alias(function ($key, $value) {
            $this->options[$key] = $value;
            return true;
        });
        Functions\when('is_wp_error')->alias(static fn($x) => $x instanceof \WP_Error);
        Functions\when('wp_remote_retrieve_body')->alias(
            static fn($r) => is_array($r) ? ($r['body'] ?? '') : ''
        );
        Functions\when('wp_remote_retrieve_response_code')->alias(
            static fn($r) => is_array($r) ? (int) ($r['response']['code'] ?? 0) : 0
        );
    }

    protected function tearDown(): void
    {
        LoggerFactory::reset();
        Monkey\tearDown();
        parent::tearDown();
    }

    private function configure(): void
    {
        $this->options['dataflair_api_token']    = 'tok';
        $this->options['dataflair_api_base_url'] = 'https://api.example.com/api/v1';
    }

    public function test_contract_check_is_registered(): void
    {
        $this->assertArrayHasKey('contract_check', TestsRunner::registry());
    }

    public function test_warns_without_hitting_network_when_unconfigured(): void
    {
        Functions\expect('wp_remote_get')->never();

        $result = (new TestsRunner())->run('contract_check');

        $this->assertSame('warn', $result['status']);
        $this->assertStringContainsString('skipped', $result['message']);
    }

    public function test_probes_live_even_while_a_mismatch_is_recorded_and_reports_recovery(): void
    {
        $this->configure();
        \SyncFunctionStubsStore::$options[ContractMismatch::OPTION] = [
            'toplists' => ['message' => 'Old mismatch.', 'min_plugin_version' => ''],
        ];
        Functions\expect('wp_remote_get')->once()->andReturn([
            'body'     => json_encode(['data' => [], 'meta' => ['last_page' => 1]]),
            'response' => ['code' => 200],
        ]);

        $result = (new TestsRunner())->run('contract_check');

        $this->assertSame('warn', $result['status']);
        $this->assertStringContainsString('still paused', $result['message']);
        $this->assertStringContainsString('Run a full sync', $result['message']);
    }

    public function test_live_409_mismatch_reports_fail(): void
    {
        $this->configure();
        Functions\expect('wp_remote_get')->once()->andReturn([
            'body'     => json_encode([
                'error_code'         => 'contract_mismatch',
                'message'            => 'Plugin too old.',
                'min_plugin_version' => '2.5.0',
            ]),
            'response' => ['code' => 409],
        ]);

        $result = (new TestsRunner())->run('contract_check');

        $this->assertSame('fail', $result['status']);
        $this->assertStringContainsString('rejected the contract', $result['message']);
    }

    public function test_healthy_probe_with_nothing_recorded_passes(): void
    {
        $this->configure();
        Functions\expect('wp_remote_get')->once()->andReturn([
            'body'     => json_encode(['data' => [], 'meta' => ['last_page' => 1]]),
            'response' => ['code' => 200],
        ]);

        $result = (new TestsRunner())->run('contract_check');

        $this->assertSame('pass', $result['status']);
    }
}
