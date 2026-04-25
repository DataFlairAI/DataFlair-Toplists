<?php
/**
 * Phase 9.8 — Pins WidgetShortcodeDetector behaviour.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Frontend\Assets;

use DataFlair\Toplists\Frontend\Assets\WidgetShortcodeDetector;
use DataFlair\Toplists\Tests\FrontendAssets\FrontendAssetsStubs as S;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FrontendAssetsTestStubs.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Frontend/Assets/WidgetShortcodeDetector.php';

final class WidgetShortcodeDetectorTest extends TestCase
{
    protected function setUp(): void
    {
        S::reset();
        WidgetShortcodeDetector::resetForTests();
    }

    public function test_register_hooks_widget_text_filter(): void
    {
        (new WidgetShortcodeDetector())->register();

        $hooks = array_column(S::$filters, 'hook');
        $this->assertContains('widget_text', $hooks);
    }

    public function test_check_flips_flag_when_widget_uses_shortcode(): void
    {
        $widgetText = '[dataflair_toplist id=1]';
        S::$hasShortcode[$widgetText] = ['dataflair_toplist' => true];

        $result = (new WidgetShortcodeDetector())->check($widgetText);

        $this->assertSame($widgetText, $result, 'must return the text unchanged');
        $this->assertTrue(WidgetShortcodeDetector::$shortcodeUsed);
    }

    public function test_check_leaves_flag_alone_when_widget_has_no_shortcode(): void
    {
        $widgetText = 'Just plain widget text';
        S::$hasShortcode[$widgetText] = ['dataflair_toplist' => false];

        (new WidgetShortcodeDetector())->check($widgetText);

        $this->assertFalse(WidgetShortcodeDetector::$shortcodeUsed);
    }
}
