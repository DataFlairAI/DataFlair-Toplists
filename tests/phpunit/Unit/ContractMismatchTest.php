<?php
/**
 * API Contract Safety P2 — pins ContractMismatch detection + persistence.
 *
 * Detection must be conservative: only an HTTP 409 whose JSON body carries
 * error_code=contract_mismatch counts. Any other 409 (proxies, unrelated
 * plugins, generic conflicts) must fall through to the generic error path
 * so sync is never paused with a misleading notice.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Sync;

use DataFlair\Toplists\Sync\ContractMismatch;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/ContractMismatch.php';
require_once __DIR__ . '/SyncFunctionStubs.php';

final class ContractMismatchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \SyncFunctionStubsStore::reset();
    }

    public function test_detects_409_with_contract_mismatch_error_code(): void
    {
        $info = ContractMismatch::fromResponse(409, json_encode([
            'error_code'         => 'contract_mismatch',
            'message'            => 'Plugin 2.2.12 is below the minimum supported version.',
            'min_plugin_version' => '2.5.0',
        ]));

        $this->assertNotNull($info);
        $this->assertSame('Plugin 2.2.12 is below the minimum supported version.', $info['message']);
        $this->assertSame('2.5.0', $info['min_plugin_version']);
    }

    public function test_ignores_409_with_other_error_code(): void
    {
        $this->assertNull(ContractMismatch::fromResponse(409, json_encode([
            'error_code' => 'edit_conflict',
            'message'    => 'Someone else saved first.',
        ])));
    }

    public function test_ignores_409_with_non_json_body(): void
    {
        $this->assertNull(ContractMismatch::fromResponse(409, '<html>Conflict</html>'));
    }

    public function test_ignores_non_409_status_even_with_matching_body(): void
    {
        $body = json_encode(['error_code' => 'contract_mismatch', 'message' => 'x']);
        $this->assertNull(ContractMismatch::fromResponse(200, $body));
        $this->assertNull(ContractMismatch::fromResponse(500, $body));
    }

    public function test_defaults_message_and_min_version_when_absent(): void
    {
        $info = ContractMismatch::fromResponse(409, json_encode([
            'error_code' => 'contract_mismatch',
        ]));

        $this->assertNotNull($info);
        $this->assertNotSame('', $info['message']);
        $this->assertSame('', $info['min_plugin_version']);
    }

    public function test_record_persists_state_and_clear_removes_it(): void
    {
        ContractMismatch::record(
            ['message' => 'Mismatch.', 'min_plugin_version' => '2.5.0'],
            'https://api.example.com/api/v1/toplists'
        );

        $state = \SyncFunctionStubsStore::$options[ContractMismatch::OPTION] ?? null;
        $this->assertIsArray($state);
        $this->assertSame('Mismatch.', $state['message']);
        $this->assertSame('2.5.0', $state['min_plugin_version']);
        $this->assertSame('https://api.example.com/api/v1/toplists', $state['url']);
        $this->assertSame(DATAFLAIR_VERSION, $state['plugin_version']);
        $this->assertGreaterThan(0, $state['detected_at']);

        ContractMismatch::clear();
        $this->assertArrayNotHasKey(ContractMismatch::OPTION, \SyncFunctionStubsStore::$options);
    }
}
