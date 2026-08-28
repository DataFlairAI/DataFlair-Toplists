<?php
/**
 * API Contract Safety — pins ContractVersion awareness.
 *
 * Answers "how does a tenant find out the API changed?". The rules under
 * test: an old backend without /meta is silent, the first reading is a silent
 * baseline, and after that a moved revision or a newly available API version
 * surfaces exactly once until acknowledged.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Sync;

use DataFlair\Toplists\Http\HttpClientInterface;
use DataFlair\Toplists\Support\WallClockBudget;
use DataFlair\Toplists\Sync\ContractVersion;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'includes/Support/WallClockBudget.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Http/HttpClientInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/ContractVersion.php';
require_once DATAFLAIR_PLUGIN_DIR . 'tests/phpunit/WpErrorStub.php';
require_once __DIR__ . '/SyncFunctionStubs.php';

final class ContractVersionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \SyncFunctionStubsStore::reset();
    }

    private function http(mixed $response): HttpClientInterface
    {
        return new class($response) implements HttpClientInterface {
            public function __construct(private mixed $response) {}
            public function get(string $url, string $token, int $timeout = 12, int $max_retries = 2, ?WallClockBudget $budget = null)
            {
                return $this->response;
            }
        };
    }

    private function metaResponse(string $rev, array $supported, string $using = 'v1'): array
    {
        return [
            'body'     => json_encode([
                'api_version'           => $using,
                'supported_contracts'   => $supported,
                'contract_rev'          => $rev,
                'latest_plugin_version' => '2.3.0',
            ]),
            'response' => ['code' => 200],
        ];
    }

    public function test_old_backend_without_meta_yields_null(): void
    {
        $http = $this->http(['body' => 'Not Found', 'response' => ['code' => 404]]);

        $this->assertNull(ContractVersion::fetch($http, 'https://api.example.com/api/v1', 'tok'));
    }

    public function test_transport_error_yields_null(): void
    {
        $http = $this->http(new \WP_Error('timeout', 'slow'));

        $this->assertNull(ContractVersion::fetch($http, 'https://api.example.com/api/v1', 'tok'));
    }

    public function test_parses_a_meta_payload(): void
    {
        $http = $this->http($this->metaResponse('1.0.0', ['v1', 'v2']));

        $meta = ContractVersion::fetch($http, 'https://api.example.com/api/v1', 'tok');

        $this->assertSame('1.0.0', $meta['rev']);
        $this->assertSame(['v1', 'v2'], $meta['supported']);
        $this->assertSame('v1', $meta['using']);
        $this->assertSame('2.3.0', $meta['latest_plugin']);
    }

    public function test_first_reading_is_a_silent_baseline(): void
    {
        // Announcing "the API is at 1.0.0" to every site on upgrade day is
        // noise, not news.
        ContractVersion::record(['rev' => '1.0.0', 'supported' => ['v1'], 'using' => 'v1', 'latest_plugin' => '']);

        $this->assertNull(ContractVersion::pending());
    }

    public function test_a_moved_revision_surfaces_once_then_stays_quiet(): void
    {
        ContractVersion::record(['rev' => '1.0.0', 'supported' => ['v1'], 'using' => 'v1', 'latest_plugin' => '']);
        ContractVersion::pending(); // baseline

        ContractVersion::record(['rev' => '1.1.0', 'supported' => ['v1'], 'using' => 'v1', 'latest_plugin' => '']);

        $pending = ContractVersion::pending();
        $this->assertNotNull($pending);
        $this->assertSame('1.1.0', $pending['rev']);
        $this->assertSame('1.0.0', $pending['previous']);

        ContractVersion::acknowledge();
        $this->assertNull(ContractVersion::pending(), 'an acknowledged revision must not nag');
    }

    public function test_a_newly_available_api_version_surfaces(): void
    {
        ContractVersion::record(['rev' => '1.0.0', 'supported' => ['v1'], 'using' => 'v1', 'latest_plugin' => '']);
        ContractVersion::pending(); // baseline

        ContractVersion::record(['rev' => '1.0.0', 'supported' => ['v1', 'v2'], 'using' => 'v1', 'latest_plugin' => '']);

        $pending = ContractVersion::pending();
        $this->assertNotNull($pending);
        $this->assertSame(['v2'], $pending['newer_versions']);
        $this->assertSame('v1', $pending['using']);
    }

    public function test_older_or_equal_versions_are_not_news(): void
    {
        ContractVersion::record(['rev' => '2.0.0', 'supported' => ['v1', 'v2'], 'using' => 'v2', 'latest_plugin' => '']);
        ContractVersion::pending(); // baseline

        $this->assertNull(ContractVersion::pending(), 'being on the newest version is not an announcement');
    }

    public function test_nothing_recorded_yields_nothing_pending(): void
    {
        $this->assertNull(ContractVersion::pending());
    }
}
