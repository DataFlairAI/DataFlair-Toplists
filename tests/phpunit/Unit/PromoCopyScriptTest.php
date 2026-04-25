<?php
/**
 * Phase 9.8 — Pins PromoCopyScript output.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Frontend\Assets;

use DataFlair\Toplists\Frontend\Assets\PromoCopyScript;
use DataFlair\Toplists\Tests\FrontendAssets\FrontendAssetsStubs as S;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FrontendAssetsTestStubs.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Frontend/Assets/PromoCopyScript.php';

final class PromoCopyScriptTest extends TestCase
{
    protected function setUp(): void
    {
        S::reset();
    }

    public function test_register_hooks_wp_footer(): void
    {
        (new PromoCopyScript())->register();

        $hook = S::$actions[0]['hook'] ?? null;
        $priority = S::$actions[0]['priority'] ?? null;

        $this->assertSame('wp_footer', $hook);
        $this->assertSame(20, $priority, 'must hook at priority 20 to run after AlpineJsEnqueuer (priority 5)');
    }

    public function test_output_emits_promo_bound_guard(): void
    {
        ob_start();
        (new PromoCopyScript())->output();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('promo-code-copy', $html);
        // The dataset.promoBound JS guard prevents duplicate listener attachment.
        $this->assertStringContainsString('dataset.promoBound', $html);
        $this->assertStringContainsString('navigator.clipboard.writeText', $html);
        $this->assertStringContainsString('Copied!', $html);
        $this->assertStringContainsString('initPromoCopy', $html);
    }
}
