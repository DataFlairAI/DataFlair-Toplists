<?php
/**
 * Phase 9.8 — Pins StylesEnqueuer behaviour.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Frontend\Assets;

use DataFlair\Toplists\Frontend\Assets\StylesEnqueuer;
use DataFlair\Toplists\Tests\FrontendAssets\FrontendAssetsStubs as S;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FrontendAssetsTestStubs.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Frontend/Assets/StylesEnqueuer.php';

final class StylesEnqueuerTest extends TestCase
{
    protected function setUp(): void
    {
        S::reset();
    }

    public function test_register_hooks_wp_enqueue_scripts(): void
    {
        (new StylesEnqueuer('/tmp/missing/', 'http://example.test/', '9.9.9'))->register();

        $hooks = array_column(S::$actions, 'hook');
        $this->assertContains('wp_enqueue_scripts', $hooks);
    }

    public function test_enqueue_uses_fallback_version_when_file_missing(): void
    {
        (new StylesEnqueuer('/tmp/no-such-dir-xyz-123/', 'http://example.test/', '9.9.9'))->enqueue();

        $this->assertCount(1, S::$enqueuedStyles);
        $row = S::$enqueuedStyles[0];
        $this->assertSame('dataflair-toplists', $row['handle']);
        $this->assertSame('http://example.test/assets/style.css', $row['src']);
        $this->assertSame('9.9.9', $row['version']);
    }

    public function test_enqueue_uses_filemtime_when_file_exists(): void
    {
        $tmp = sys_get_temp_dir() . '/dataflair-styles-test-' . uniqid() . '/';
        @mkdir($tmp . 'assets/', 0777, true);
        file_put_contents($tmp . 'assets/style.css', '/* test */');

        try {
            (new StylesEnqueuer($tmp, 'http://example.test/', '9.9.9'))->enqueue();

            $this->assertCount(1, S::$enqueuedStyles);
            $version = S::$enqueuedStyles[0]['version'];
            $this->assertNotSame('9.9.9', $version);
            $this->assertMatchesRegularExpression('/^\d+$/', (string) $version);
        } finally {
            @unlink($tmp . 'assets/style.css');
            @rmdir($tmp . 'assets/');
            @rmdir($tmp);
        }
    }
}
