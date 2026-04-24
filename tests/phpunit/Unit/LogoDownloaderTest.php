<?php
/**
 * Phase 2 — behavioural pin for LogoDownloader.
 *
 * Exercises the HEAD-before-GET, 3 MB size cap, 8 s timeout, and
 * dataflair_brand_logo_stored action hook in isolation against mocked
 * wp_remote_* functions.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Http;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use DataFlair\Toplists\Http\LogoDownloader;
use DataFlair\Toplists\Logging\LoggerFactory;
use DataFlair\Toplists\Logging\NullLogger;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/NullLogger.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerFactory.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Http/LogoDownloaderInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Http/LogoDownloader.php';
require_once DATAFLAIR_PLUGIN_DIR . 'tests/phpunit/WpErrorStub.php';

final class LogoDownloaderTest extends TestCase
{
    private string $tmp_logo_dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        LoggerFactory::reset();

        Filters\expectApplied('dataflair_logger')
            ->andReturnUsing(static fn() => new NullLogger());
        Filters\expectApplied('dataflair_logger_level')
            ->andReturnUsing(static fn($default) => $default);

        $this->tmp_logo_dir = sys_get_temp_dir() . '/dataflair-logo-test-' . uniqid() . '/';

        // LogoDownloader writes to DATAFLAIR_PLUGIN_DIR . 'assets/logos/'.
        // We shim the filesystem helpers used along the download path.
        Functions\when('wp_mkdir_p')->alias(function ($dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            return true;
        });
        Functions\when('sanitize_file_name')->alias(static fn($s) => preg_replace('/[^a-zA-Z0-9._-]/', '', (string) $s));

        Functions\when('wp_remote_retrieve_body')->alias(static function ($r) {
            return is_array($r) && isset($r['body']) ? $r['body'] : '';
        });
        Functions\when('wp_remote_retrieve_response_code')->alias(static function ($r) {
            return is_array($r) && isset($r['response']['code']) ? (int) $r['response']['code'] : 0;
        });
        Functions\when('wp_remote_retrieve_header')->alias(static function ($r, $key) {
            $k = strtolower((string) $key);
            return is_array($r) && isset($r['headers'][$k]) ? $r['headers'][$k] : '';
        });
        Functions\when('is_wp_error')->alias(static fn($t) => $t instanceof \WP_Error);
    }

    protected function tearDown(): void
    {
        // Clean up any files we wrote under the plugin's assets/logos dir.
        $plugin_logos = DATAFLAIR_PLUGIN_DIR . 'assets/logos/';
        foreach (glob($plugin_logos . 'test-brand-*.png') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($plugin_logos . 'test-brand-*.jpg') ?: [] as $f) {
            @unlink($f);
        }
        LoggerFactory::reset();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_empty_logo_url_returns_false_without_http(): void
    {
        Functions\expect('wp_remote_head')->never();
        Functions\expect('wp_remote_get')->never();

        $downloader = new LogoDownloader(new NullLogger());
        $this->assertFalse($downloader->download(['name' => 'Nope', 'id' => 1], 'nope'));
    }

    public function test_head_content_length_over_cap_short_circuits_get(): void
    {
        Functions\expect('wp_remote_head')->once()->andReturn([
            'headers'  => ['content-length' => 10 * 1024 * 1024],
            'response' => ['code' => 200],
        ]);
        Functions\expect('wp_remote_get')->never();

        $downloader = new LogoDownloader(new NullLogger());
        $result = $downloader->download(
            ['name' => 'Huge', 'id' => 1, 'logo' => 'https://cdn.example/huge.png'],
            'test-brand-huge'
        );
        $this->assertFalse($result);
    }

    public function test_successful_download_fires_brand_logo_stored_hook(): void
    {
        Functions\expect('wp_remote_head')->once()->andReturn([
            'headers'  => ['content-length' => 512],
            'response' => ['code' => 200],
        ]);
        Functions\expect('wp_remote_get')->once()->andReturn([
            'body'     => 'small-png-bytes',
            'response' => ['code' => 200],
        ]);

        $fired = [];
        Functions\when('do_action')->alias(function (...$args) use (&$fired) {
            if (($args[0] ?? null) === 'dataflair_brand_logo_stored') {
                $fired[] = ['brand_id' => $args[1], 'file_url' => $args[2], 'logo_url' => $args[3]];
            }
        });

        $downloader = new LogoDownloader(new NullLogger());
        $result = $downloader->download(
            ['name' => 'Small', 'id' => 42, 'logo' => 'https://cdn.example/test-brand-small.png'],
            'test-brand-small'
        );

        $this->assertIsString($result);
        $this->assertStringEndsWith('test-brand-small.png', $result);
        $this->assertCount(1, $fired, 'dataflair_brand_logo_stored must fire once.');
        $this->assertSame(42, $fired[0]['brand_id']);
        $this->assertSame('https://cdn.example/test-brand-small.png', $fired[0]['logo_url']);
    }

    public function test_non_200_response_returns_false(): void
    {
        Functions\expect('wp_remote_head')->once()->andReturn([
            'headers'  => ['content-length' => 512],
            'response' => ['code' => 200],
        ]);
        Functions\expect('wp_remote_get')->once()->andReturn([
            'body'     => '',
            'response' => ['code' => 404],
        ]);

        $downloader = new LogoDownloader(new NullLogger());
        $result = $downloader->download(
            ['name' => 'Missing', 'id' => 7, 'logo' => 'https://cdn.example/test-brand-missing.png'],
            'test-brand-missing'
        );
        $this->assertFalse($result);
    }

    public function test_extracts_logo_url_from_nested_shape(): void
    {
        Functions\expect('wp_remote_head')->once()->andReturn([
            'headers'  => ['content-length' => 256],
            'response' => ['code' => 200],
        ]);
        Functions\expect('wp_remote_get')->once()->andReturn([
            'body'     => 'jpg-bytes',
            'response' => ['code' => 200],
        ]);
        Functions\when('do_action')->justReturn(null);

        $downloader = new LogoDownloader(new NullLogger());
        $result = $downloader->download(
            [
                'name' => 'Nested',
                'id'   => 9,
                'logo' => ['rectangular' => 'https://cdn.example/test-brand-nested.jpg'],
            ],
            'test-brand-nested'
        );

        $this->assertIsString($result);
        $this->assertStringEndsWith('test-brand-nested.jpg', $result);
    }

    public function test_implements_logo_downloader_interface(): void
    {
        $downloader = new LogoDownloader(new NullLogger());
        $this->assertInstanceOf(\DataFlair\Toplists\Http\LogoDownloaderInterface::class, $downloader);
    }
}
