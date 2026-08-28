<?php
/**
 * API Contract Safety P2 — pins ContractMismatch detection + persistence.
 *
 * Detection must be conservative: only an HTTP 409 whose JSON body carries
 * error_code=contract_mismatch counts. State is one entry per sync stream so
 * a toplists success can never hide a brands mismatch or vice versa, and
 * upstream-controlled text is sanitized before it can reach wp-admin markup.
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

    public function test_sanitizes_hostile_message_and_non_scalar_fields(): void
    {
        $info = ContractMismatch::fromResponse(409, json_encode([
            'error_code'         => 'contract_mismatch',
            'message'            => '<img src=x onerror=alert(1)>Broken ' . str_repeat('x', 500),
            'min_plugin_version' => ['not' => 'a version'],
        ]));

        $this->assertNotNull($info);
        $this->assertStringNotContainsString('<', $info['message']);
        $this->assertStringNotContainsString('onerror', $info['message']);
        $this->assertLessThanOrEqual(300, strlen($info['message']));
        $this->assertSame('', $info['min_plugin_version']);
    }

    public function test_describe_composes_the_full_admin_sentence(): void
    {
        $sentence = ContractMismatch::describe([
            'message'            => 'Plugin too old.',
            'min_plugin_version' => '2.5.0',
        ]);

        $this->assertStringContainsString('DataFlair API contract mismatch: Plugin too old.', $sentence);
        $this->assertStringContainsString('2.5.0 or newer', $sentence);
        $this->assertStringContainsString('last synced data', $sentence);

        $noMin = ContractMismatch::describe(['message' => 'Mismatch.', 'min_plugin_version' => '']);
        $this->assertStringNotContainsString('or newer', $noMin);
    }

    public function test_record_keeps_one_entry_per_stream(): void
    {
        ContractMismatch::record(
            ['message' => 'Brands v2 mismatch.', 'min_plugin_version' => ''],
            'https://api.example.com/api/v2/brands',
            'brands'
        );
        ContractMismatch::record(
            ['message' => 'Toplists v1 mismatch.', 'min_plugin_version' => '2.5.0'],
            'https://api.example.com/api/v1/toplists',
            'toplists'
        );

        $entries = ContractMismatch::entries();
        $this->assertArrayHasKey('brands', $entries, 'recording toplists must not erase the brands entry');
        $this->assertArrayHasKey('toplists', $entries);
        $this->assertSame('2.5.0', $entries['toplists']['min_plugin_version']);
        $this->assertSame(DATAFLAIR_VERSION, $entries['toplists']['plugin_version']);
        $this->assertGreaterThan(0, $entries['toplists']['detected_at']);
    }

    public function test_clear_is_source_scoped_and_leaves_other_streams(): void
    {
        ContractMismatch::record(['message' => 'Brands mismatch.', 'min_plugin_version' => ''], 'u', 'brands');
        ContractMismatch::record(['message' => 'Toplists mismatch.', 'min_plugin_version' => ''], 'u', 'toplists');

        ContractMismatch::clear('toplists');
        $entries = ContractMismatch::entries();
        $this->assertArrayNotHasKey('toplists', $entries);
        $this->assertArrayHasKey('brands', $entries, 'a toplists success must never hide a brands mismatch');

        ContractMismatch::clear('brands');
        $this->assertSame([], ContractMismatch::entries());
        $this->assertArrayNotHasKey(ContractMismatch::OPTION, \SyncFunctionStubsStore::$options);
    }

    public function test_clear_with_nothing_recorded_issues_no_write(): void
    {
        ContractMismatch::clear('toplists');

        $this->assertArrayNotHasKey(ContractMismatch::OPTION, \SyncFunctionStubsStore::$options);
    }

    public function test_entries_tolerates_corrupted_option_shapes(): void
    {
        \SyncFunctionStubsStore::$options[ContractMismatch::OPTION] = 'corrupted string';
        $this->assertSame([], ContractMismatch::entries());

        \SyncFunctionStubsStore::$options[ContractMismatch::OPTION] = ['toplists' => 'not an entry', 'brands' => ['message' => 'ok']];
        $entries = ContractMismatch::entries();
        $this->assertArrayNotHasKey('toplists', $entries);
        $this->assertArrayHasKey('brands', $entries);
    }
}
