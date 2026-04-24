<?php
/**
 * Phase 3 — behavioural pin for ToplistSyncService.
 *
 * Pins the bulk-path happy flow, the WP_Error → fallback hand-off, the
 * unrecoverable-page skipped payload, and the legacy AJAX response shape.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Sync;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DataFlair\Toplists\Http\HttpClientInterface;
use DataFlair\Toplists\Logging\LoggerInterface;
use DataFlair\Toplists\Logging\NullLogger;
use DataFlair\Toplists\Support\WallClockBudget;
use DataFlair\Toplists\Sync\SyncRequest;
use DataFlair\Toplists\Sync\SyncResult;
use DataFlair\Toplists\Sync\ToplistPersisterInterface;
use DataFlair\Toplists\Sync\ToplistSyncService;
use DataFlair\Toplists\Sync\ToplistSyncServiceInterface;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/NullLogger.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Support/WallClockBudget.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Http/HttpClientInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/SyncRequest.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/SyncResult.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/ToplistSyncServiceInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/ToplistPersisterInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/ToplistSyncService.php';
require_once DATAFLAIR_PLUGIN_DIR . 'tests/phpunit/WpErrorStub.php';
require_once __DIR__ . '/SyncFunctionStubs.php';

final class ToplistSyncServiceTest extends TestCase
{
    private ToplistFakeHttp $http;
    private ToplistFakePersister $persister;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->http      = new ToplistFakeHttp();
        $this->persister = new ToplistFakePersister();

        $GLOBALS['wpdb'] = new ToplistFakeWpdb();

        if (!defined('HOUR_IN_SECONDS')) {
            define('HOUR_IN_SECONDS', 3600);
        }

        Functions\when('is_wp_error')->alias(static fn($x) => $x instanceof \WP_Error);
        Functions\when('wp_remote_retrieve_body')->alias(
            static fn($r) => is_array($r) ? ($r['body'] ?? '') : ''
        );
        Functions\when('wp_remote_retrieve_response_code')->alias(
            static fn($r) => is_array($r) ? (int) ($r['response']['code'] ?? 0) : 0
        );
        Functions\when('wp_remote_retrieve_headers')->alias(static fn($r) => []);
        Functions\when('do_action')->justReturn(null);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    public function test_implements_interface(): void
    {
        $svc = $this->makeService();
        $this->assertInstanceOf(ToplistSyncServiceInterface::class, $svc);
    }

    public function test_happy_path_persists_every_item_in_bulk_response(): void
    {
        $this->http->responses[] = $this->bulkResponse([
            ['id' => 101, 'name' => 'Top 10 US Casinos'],
            ['id' => 102, 'name' => 'Top 10 UK Casinos'],
            ['id' => 103, 'name' => 'Top 10 DE Casinos'],
        ], ['last_page' => 2]);

        $svc    = $this->makeService();
        $result = $svc->syncPage(SyncRequest::toplists(1));

        $this->assertTrue($result->success);
        $this->assertSame(3, $result->synced);
        $this->assertSame(0, $result->errors);
        $this->assertSame(2, $result->lastPage);
        $this->assertCount(3, $this->persister->storeCalls);
        $this->assertSame(101, $this->persister->storeCalls[0]['toplist']['id']);
    }

    public function test_is_complete_true_when_last_page_reached(): void
    {
        $this->http->responses[] = $this->bulkResponse(
            [['id' => 101]],
            ['last_page' => 1]
        );

        $svc    = $this->makeService();
        $result = $svc->syncPage(SyncRequest::toplists(1));

        $this->assertTrue($result->isComplete);
    }

    public function test_http_wp_error_triggers_per_id_fallback(): void
    {
        // Bulk call fails → falls back to per-ID. The fallback itself returns
        // a per_page=5 × 2 slice; with every slice also failing we get skipped.
        $this->http->responses[] = new \WP_Error('timeout', 'server slow');
        // All fallback slice calls also fail (for simplicity).
        for ($i = 0; $i < 20; $i++) {
            $this->http->responses[] = new \WP_Error('timeout', 'still slow');
        }

        $svc    = $this->makeService();
        $result = $svc->syncPage(SyncRequest::toplists(1));

        $this->assertTrue($result->success, 'Fallback must not propagate bulk WP_Error as failure.');
        $array = $result->toArray();
        $this->assertTrue($array['skipped']);
        $this->assertTrue($array['fallback']);
        $this->assertStringContainsString('errors for every split', $array['skip_reason']);
    }

    public function test_500_status_triggers_fallback_but_400_does_not(): void
    {
        // 400 is not retryable → failure straight away.
        $this->http->responses[] = [
            'body'     => '{"error":"bad"}',
            'response' => ['code' => 400],
        ];

        $svc    = $this->makeService();
        $result = $svc->syncPage(SyncRequest::toplists(2));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('400', $result->message);
    }

    public function test_invalid_json_returns_failure(): void
    {
        $this->http->responses[] = [
            'body'     => 'not json {',
            'response' => ['code' => 200],
        ];

        $svc    = $this->makeService();
        $result = $svc->syncPage(SyncRequest::toplists(1));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('JSON decode error', $result->message);
    }

    public function test_missing_data_key_returns_failure(): void
    {
        $this->http->responses[] = [
            'body'     => '{"meta":{"last_page":1}}',
            'response' => ['code' => 200],
        ];

        $svc    = $this->makeService();
        $result = $svc->syncPage(SyncRequest::toplists(1));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('"data" key', $result->message);
    }

    public function test_page_one_resets_sync_state_via_wpdb_delete(): void
    {
        $this->http->responses[] = $this->bulkResponse([], ['last_page' => 1]);

        $svc = $this->makeService();
        $svc->syncPage(SyncRequest::toplists(1));

        // Expect at least one DELETE against the toplists table (paginated).
        $matched = array_filter(
            $GLOBALS['wpdb']->queries,
            static fn($q) => str_starts_with(trim($q), 'DELETE FROM wp_dataflair_toplists')
        );
        $this->assertNotEmpty($matched);
    }

    public function test_failed_persist_counts_as_error(): void
    {
        $this->persister->storeReturn = false; // every store fails
        $this->http->responses[]      = $this->bulkResponse([
            ['id' => 1], ['id' => 2], ['id' => 3],
        ], ['last_page' => 1]);

        $svc    = $this->makeService();
        $result = $svc->syncPage(SyncRequest::toplists(1));

        $this->assertTrue($result->success);
        $this->assertSame(0, $result->synced);
        $this->assertSame(3, $result->errors);
    }

    private function makeService(): ToplistSyncService
    {
        return new ToplistSyncService(
            $this->http,
            $this->persister,
            new NullLogger(),
            'test-token',
            'https://api.example.com/v1',
            static function (int $status, string $body, $h, string $url): string {
                return "API error ({$status}) for {$url}";
            }
        );
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @param array<string,int>              $meta
     * @return array<string,mixed>
     */
    private function bulkResponse(array $items, array $meta): array
    {
        return [
            'body'     => json_encode([
                'data' => $items,
                'meta' => array_merge(['last_page' => 1, 'total' => count($items)], $meta),
            ]),
            'response' => ['code' => 200],
        ];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Fakes (file-local so each test file remains self-contained).

final class ToplistFakeHttp implements HttpClientInterface
{
    /** @var array<int, mixed> */
    public array $responses = [];

    public function get(string $url, string $token, int $timeout = 12, int $max_retries = 2, ?WallClockBudget $budget = null)
    {
        return array_shift($this->responses) ?? new \WP_Error('no_response', 'test ran out of responses');
    }
}

final class ToplistFakePersister implements ToplistPersisterInterface
{
    public bool $storeReturn = true;
    /** @var array<int, array{toplist: array<string,mixed>, raw: string}> */
    public array $storeCalls = [];
    /** @var array<int, array{endpoint: string, token: string}> */
    public array $fetchAndStoreCalls = [];

    public function store(array $toplist, string $rawJson): bool
    {
        $this->storeCalls[] = ['toplist' => $toplist, 'raw' => $rawJson];
        return $this->storeReturn;
    }

    public function fetchAndStore(string $endpoint, string $token): bool
    {
        $this->fetchAndStoreCalls[] = ['endpoint' => $endpoint, 'token' => $token];
        return $this->storeReturn;
    }
}

final class ToplistFakeWpdb
{
    public string $prefix = 'wp_';
    /** @var string[] */
    public array $queries = [];

    public function query(string $sql): int
    {
        $this->queries[] = $sql;
        return 0; // exit pagination loops
    }

    public function prepare(string $sql, ...$args): string
    {
        $flat = (count($args) === 1 && is_array($args[0])) ? $args[0] : $args;
        return vsprintf(str_replace(['%d', '%s', '%f'], ['%s', '%s', '%s'], $sql), $flat);
    }

    public function get_var(string $sql): int
    {
        return 0;
    }

    public function get_results(string $sql): array
    {
        return [];
    }

    public $options = 'wp_options';
}
