<?php
/**
 * Behavioural pin for PersistentCurlTransport — the DEFAULT transport for
 * ApiClient/LogoDownloader whenever the curl extension is present (i.e.
 * virtually every real deployment). ApiClientTest/LogoDownloaderTest force
 * `dataflair_use_persistent_curl` to false and never exercise this class.
 *
 * curl is a compiled extension: Brain\Monkey/Patchwork cannot intercept
 * curl_init()/curl_exec()/etc (confirmed — a Functions\when('curl_init')
 * stub is silently ignored and the real function runs anyway). Coverage
 * here goes through PersistentCurlTransport::setDriver(), which swaps the
 * CurlDriverInterface collaborator so the real method bodies (get()/head(),
 * including the WRITEFUNCTION closure) run unchanged against a fake curl
 * backend instead of the real extension.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Http;

use DataFlair\Toplists\Http\CurlDriverInterface;
use DataFlair\Toplists\Http\PersistentCurlTransport;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Http/CurlDriverInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Http/PersistentCurlTransport.php';

final class PersistentCurlTransportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        PersistentCurlTransport::resetDriver();
    }

    protected function tearDown(): void
    {
        PersistentCurlTransport::resetDriver();
        parent::tearDown();
    }

    // ── URL / Basic Auth (URL-credentials) pass-through ──────────────────

    public function test_get_passes_url_verbatim_into_curlopt_url(): void
    {
        $driver = new FakeCurlDriver();
        PersistentCurlTransport::setDriver($driver);

        $url = 'https://user:pass@api.example.com/toplists';
        PersistentCurlTransport::get($url, [], 5, 1024, true);

        $this->assertSame($url, $driver->lastOptions()[CURLOPT_URL]);
    }

    public function test_head_passes_url_and_ssl_flags_verbatim(): void
    {
        $driver = new FakeCurlDriver();
        $driver->getInfoReturns = [CURLINFO_RESPONSE_CODE => 200, CURLINFO_CONTENT_LENGTH_DOWNLOAD => 0];
        PersistentCurlTransport::setDriver($driver);

        $url = 'https://cdn.example.com/logo.png';
        PersistentCurlTransport::head($url, 5, false);

        $opts = $driver->lastOptions();
        $this->assertSame($url, $opts[CURLOPT_URL]);
        $this->assertFalse($opts[CURLOPT_SSL_VERIFYPEER]);
        $this->assertSame(0, $opts[CURLOPT_SSL_VERIFYHOST]);
    }

    // ── Header injection ──────────────────────────────────────────────────

    public function test_get_flattens_associative_headers_into_curlopt_httpheader(): void
    {
        $driver = new FakeCurlDriver();
        PersistentCurlTransport::setDriver($driver);

        PersistentCurlTransport::get('https://api.example.com/x', [
            'Accept'        => 'application/json',
            'Authorization' => 'Bearer tok',
        ], 5, 1024, true);

        $this->assertSame(
            ['Accept: application/json', 'Authorization: Bearer tok'],
            $driver->lastOptions()[CURLOPT_HTTPHEADER]
        );
    }

    public function test_get_passes_raw_indexed_headers_through_unchanged(): void
    {
        $driver = new FakeCurlDriver();
        PersistentCurlTransport::setDriver($driver);

        PersistentCurlTransport::get('https://api.example.com/x', [
            'X-Custom-Raw-Header: literal-value',
        ], 5, 1024, true);

        $this->assertSame(
            ['X-Custom-Raw-Header: literal-value'],
            $driver->lastOptions()[CURLOPT_HTTPHEADER]
        );
    }

    public function test_head_sets_nobody_true_and_no_headers(): void
    {
        $driver = new FakeCurlDriver();
        $driver->getInfoReturns = [CURLINFO_RESPONSE_CODE => 200, CURLINFO_CONTENT_LENGTH_DOWNLOAD => 0];
        PersistentCurlTransport::setDriver($driver);

        PersistentCurlTransport::head('https://cdn.example.com/logo.png', 5, true);

        $opts = $driver->lastOptions();
        $this->assertTrue($opts[CURLOPT_NOBODY]);
        $this->assertSame([], $opts[CURLOPT_HTTPHEADER]);
    }

    // ── Per-call timeout ──────────────────────────────────────────────────

    public function test_get_sets_timeout_from_argument(): void
    {
        $driver = new FakeCurlDriver();
        PersistentCurlTransport::setDriver($driver);

        PersistentCurlTransport::get('https://api.example.com/x', [], 7, 1024, true);

        $this->assertSame(7, $driver->lastOptions()[CURLOPT_TIMEOUT]);
    }

    public function test_get_clamps_connect_timeout_between_2_and_10_seconds(): void
    {
        $driver = new FakeCurlDriver();
        PersistentCurlTransport::setDriver($driver);

        PersistentCurlTransport::get('https://api.example.com/x', [], 1, 1024, true);
        $this->assertSame(2, $driver->lastOptions()[CURLOPT_CONNECTTIMEOUT], 'Connect timeout must not drop below 2s even for a 1s call timeout.');

        PersistentCurlTransport::get('https://api.example.com/x', [], 30, 1024, true);
        $this->assertSame(10, $driver->lastOptions()[CURLOPT_CONNECTTIMEOUT], 'Connect timeout must cap at 10s even for a long call timeout.');
    }

    // ── SSL verify flag wiring ────────────────────────────────────────────

    public function test_get_enables_ssl_verification_by_default(): void
    {
        $driver = new FakeCurlDriver();
        PersistentCurlTransport::setDriver($driver);

        PersistentCurlTransport::get('https://api.example.com/x', [], 5, 1024, true);

        $opts = $driver->lastOptions();
        $this->assertTrue($opts[CURLOPT_SSL_VERIFYPEER]);
        $this->assertSame(2, $opts[CURLOPT_SSL_VERIFYHOST]);
    }

    public function test_get_disables_ssl_verification_when_requested(): void
    {
        $driver = new FakeCurlDriver();
        PersistentCurlTransport::setDriver($driver);

        PersistentCurlTransport::get('https://local.test/x', [], 5, 1024, false);

        $opts = $driver->lastOptions();
        $this->assertFalse($opts[CURLOPT_SSL_VERIFYPEER]);
        $this->assertSame(0, $opts[CURLOPT_SSL_VERIFYHOST]);
    }

    // ── Response size cap via CURLOPT_WRITEFUNCTION abort ─────────────────

    public function test_get_writefunction_accepts_chunk_landing_exactly_at_cap(): void
    {
        $driver = new FakeCurlDriver();
        PersistentCurlTransport::setDriver($driver);
        PersistentCurlTransport::get('https://api.example.com/x', [], 5, 10, true);

        $writeFn = $driver->lastOptions()[CURLOPT_WRITEFUNCTION];
        $result  = $writeFn(null, str_repeat('a', 10));

        $this->assertSame(10, $result, 'A chunk landing exactly at the cap must be accepted in full, not truncated.');
    }

    public function test_get_writefunction_truncates_single_chunk_exceeding_cap(): void
    {
        $driver = new FakeCurlDriver();
        PersistentCurlTransport::setDriver($driver);
        PersistentCurlTransport::get('https://api.example.com/x', [], 5, 10, true);

        $writeFn = $driver->lastOptions()[CURLOPT_WRITEFUNCTION];
        $result  = $writeFn(null, str_repeat('a', 11));

        $this->assertSame(-1, $result, 'A chunk exceeding the cap must return -1 so curl aborts the transfer.');
    }

    public function test_get_writefunction_truncates_after_accumulating_across_chunks(): void
    {
        $driver = new FakeCurlDriver();
        PersistentCurlTransport::setDriver($driver);
        PersistentCurlTransport::get('https://api.example.com/x', [], 5, 10, true);

        $writeFn = $driver->lastOptions()[CURLOPT_WRITEFUNCTION];
        $first   = $writeFn(null, str_repeat('a', 6));
        $second  = $writeFn(null, str_repeat('b', 5));

        $this->assertSame(6, $first, 'First chunk under the cap must be accepted in full.');
        $this->assertSame(-1, $second, 'Cumulative bytes crossing the cap must abort on the chunk that crosses it.');
    }

    public function test_get_returns_response_too_large_when_writefunction_truncates(): void
    {
        $driver = new FakeCurlDriver();
        $driver->getInfoReturns   = [CURLINFO_RESPONSE_CODE => 200];
        $driver->feedChunksOnExec = [str_repeat('a', 20)];
        PersistentCurlTransport::setDriver($driver);

        $result = PersistentCurlTransport::get('https://api.example.com/x', [], 5, 10, true);

        $this->assertFalse($result['ok']);
        $this->assertSame('response_too_large', $result['error']);
        $this->assertTrue($result['truncated']);
    }

    // ── Success / error response shaping ───────────────────────────────────

    public function test_get_returns_ok_true_with_body_and_code_on_success(): void
    {
        $driver = new FakeCurlDriver();
        $driver->getInfoReturns   = [CURLINFO_RESPONSE_CODE => 200];
        $driver->feedChunksOnExec = ['{"ok":true}'];
        PersistentCurlTransport::setDriver($driver);

        $result = PersistentCurlTransport::get('https://api.example.com/x', [], 5, 1024, true);

        $this->assertTrue($result['ok']);
        $this->assertSame(200, $result['code']);
        $this->assertSame('{"ok":true}', $result['body']);
        $this->assertFalse($result['truncated']);
    }

    public function test_get_returns_curl_error_when_exec_reports_errno(): void
    {
        $driver = new FakeCurlDriver();
        $driver->execReturn  = false;
        $driver->errnoReturn = 28;
        $driver->errorReturn = 'Operation timed out';
        PersistentCurlTransport::setDriver($driver);

        $result = PersistentCurlTransport::get('https://api.example.com/x', [], 5, 1024, true);

        $this->assertFalse($result['ok']);
        $this->assertSame('curl_error: Operation timed out', $result['error']);
    }

    public function test_get_returns_ok_true_when_exec_fails_but_curl_reports_no_errno(): void
    {
        // Pins existing behaviour: get() only builds an error result when
        // curl_errno() is non-zero, so a false curl_exec() with errno 0
        // falls through to the success shape with an empty body.
        $driver = new FakeCurlDriver();
        $driver->execReturn  = false;
        $driver->errnoReturn = 0;
        PersistentCurlTransport::setDriver($driver);

        $result = PersistentCurlTransport::get('https://api.example.com/x', [], 5, 1024, true);

        $this->assertTrue($result['ok']);
        $this->assertSame('', $result['body']);
    }

    public function test_head_returns_content_length_on_success(): void
    {
        $driver = new FakeCurlDriver();
        $driver->getInfoReturns = [
            CURLINFO_RESPONSE_CODE          => 200,
            CURLINFO_CONTENT_LENGTH_DOWNLOAD => 4096,
        ];
        PersistentCurlTransport::setDriver($driver);

        $result = PersistentCurlTransport::head('https://cdn.example.com/logo.png', 5, true);

        $this->assertTrue($result['ok']);
        $this->assertSame(200, $result['code']);
        $this->assertSame(4096, $result['content_length']);
    }

    public function test_head_returns_curl_error_struct_when_errno_set(): void
    {
        $driver = new FakeCurlDriver();
        $driver->execReturn  = false;
        $driver->errnoReturn = 6;
        $driver->errorReturn = 'Could not resolve host';
        PersistentCurlTransport::setDriver($driver);

        $result = PersistentCurlTransport::head('https://cdn.example.com/logo.png', 5, true);

        $this->assertFalse($result['ok']);
        $this->assertSame('curl_error: Could not resolve host', $result['error']);
    }

    // ── Handle threading ────────────────────────────────────────────────

    public function test_get_and_head_thread_the_same_handle_through_every_driver_call(): void
    {
        $driver = new FakeCurlDriver();
        $driver->getInfoReturns = [CURLINFO_RESPONSE_CODE => 200, CURLINFO_CONTENT_LENGTH_DOWNLOAD => 0];
        PersistentCurlTransport::setDriver($driver);

        PersistentCurlTransport::get('https://api.example.com/x', [], 5, 1024, true);
        PersistentCurlTransport::head('https://api.example.com/x', 5, true);

        $this->assertCount(2, $driver->handlesSeenInSetOpt);
        $this->assertSame($driver->handleToken, $driver->handlesSeenInSetOpt[0]);
        $this->assertSame($driver->handleToken, $driver->handlesSeenInSetOpt[1]);
        $this->assertSame(2, $driver->handleCalls, 'Every get()/head() call must fetch the handle via the driver (which owns reuse/reset).');
    }
}

final class FakeCurlDriver implements CurlDriverInterface
{
    /** @var mixed */
    public $handleToken = 'fake-handle';

    public int $handleCalls = 0;

    /** @var array<int, mixed> */
    public array $handlesSeenInSetOpt = [];

    /** @var array<int, array> */
    public array $setOptCalls = [];

    /** @var bool */
    public $execReturn = true;

    public int $errnoReturn = 0;

    public string $errorReturn = '';

    /** @var array<int, mixed> */
    public array $getInfoReturns = [];

    /** @var array<int, string> Chunks fed to the captured WRITEFUNCTION when exec() runs, simulating a real transfer. */
    public array $feedChunksOnExec = [];

    public function handle()
    {
        $this->handleCalls++;
        return $this->handleToken;
    }

    public function setOptArray($handle, array $options): void
    {
        $this->handlesSeenInSetOpt[] = $handle;
        $this->setOptCalls[] = $options;
    }

    public function exec($handle)
    {
        $writeFn = $this->lastOptions()[CURLOPT_WRITEFUNCTION] ?? null;
        if ($writeFn !== null) {
            foreach ($this->feedChunksOnExec as $chunk) {
                $writeFn($handle, $chunk);
            }
        }
        return $this->execReturn;
    }

    public function getInfo($handle, int $option)
    {
        return $this->getInfoReturns[$option] ?? 0;
    }

    public function errno($handle): int
    {
        return $this->errnoReturn;
    }

    public function error($handle): string
    {
        return $this->errorReturn;
    }

    public function lastOptions(): array
    {
        return $this->setOptCalls[count($this->setOptCalls) - 1] ?? [];
    }
}
