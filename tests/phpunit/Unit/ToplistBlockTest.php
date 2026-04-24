<?php
/**
 * Phase 7 — pins ToplistBlock render behaviour.
 *
 * Responsibilities under test:
 *   - Returns the empty-state help text when `toplistId` is missing.
 *   - Reads settings-driven defaults via the injected option closure.
 *   - Merges user attributes over defaults (user wins).
 *   - Forwards a complete shortcode atts bag to the shortcode closure,
 *     including prosCons pass-through.
 *   - Coerces `limit` to an int.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Block;

use DataFlair\Toplists\Block\ToplistBlock;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/BlockTestStubs.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Block/ToplistBlock.php';

final class ToplistBlockTest extends TestCase
{
    public function test_returns_help_text_when_toplist_id_empty(): void
    {
        $block = new ToplistBlock(
            static fn(array $atts): string => '<SHOULD_NOT_RUN>',
            static fn(string $key, $default = null) => $default
        );

        $html = $block->render(['toplistId' => '']);
        $this->assertStringContainsString('Please configure the toplist ID', $html);
    }

    public function test_returns_help_text_when_attributes_null(): void
    {
        $block = new ToplistBlock(
            static fn(array $atts): string => '<SHOULD_NOT_RUN>',
            static fn(string $key, $default = null) => $default
        );

        $html = $block->render(null);
        $this->assertStringContainsString('Please configure the toplist ID', $html);
    }

    public function test_defaults_come_from_option_reader(): void
    {
        $captured = [];
        $options  = [
            'dataflair_ribbon_bg_color'   => 'red-500',
            'dataflair_ribbon_text_color' => 'black',
            'dataflair_cta_bg_color'      => 'blue-500',
            'dataflair_cta_text_color'    => 'white',
        ];

        $block = new ToplistBlock(
            function (array $atts) use (&$captured): string {
                $captured = $atts;
                return '<div>ok</div>';
            },
            static fn(string $key, $default = null) => $options[$key] ?? $default
        );

        $block->render(['toplistId' => 'abc123']);

        $this->assertSame('red-500', $captured['ribbonBgColor']);
        $this->assertSame('black',   $captured['ribbonTextColor']);
        $this->assertSame('blue-500', $captured['ctaBgColor']);
        $this->assertSame('white',   $captured['ctaTextColor']);
    }

    public function test_user_attributes_override_defaults(): void
    {
        $captured = [];
        $block    = new ToplistBlock(
            function (array $atts) use (&$captured): string {
                $captured = $atts;
                return '<div>ok</div>';
            },
            static fn(string $key, $default = null) => $default
        );

        $block->render([
            'toplistId'       => 'abc123',
            'title'           => 'Top 5 Casinos',
            'ribbonText'      => 'Editor Pick',
            'limit'           => '7',
            'ctaBgColor'      => 'pink-500',
        ]);

        $this->assertSame('abc123',       $captured['id']);
        $this->assertSame('Top 5 Casinos', $captured['title']);
        $this->assertSame('Editor Pick',   $captured['ribbonText']);
        $this->assertSame(7,              $captured['limit'], 'limit must be coerced to int');
        $this->assertSame('pink-500',      $captured['ctaBgColor']);
    }

    public function test_pros_cons_is_passed_through_even_though_not_in_defaults(): void
    {
        $captured = [];
        $block    = new ToplistBlock(
            function (array $atts) use (&$captured): string {
                $captured = $atts;
                return '<div>ok</div>';
            },
            static fn(string $key, $default = null) => $default
        );

        $block->render([
            'toplistId' => 'abc123',
            'prosCons'  => [
                ['itemId' => 1, 'pros' => ['fast'], 'cons' => ['pricey']],
            ],
        ]);

        $this->assertIsArray($captured['prosCons']);
        $this->assertSame(1, $captured['prosCons'][0]['itemId']);
    }

    public function test_render_returns_string_from_shortcode_closure(): void
    {
        $block = new ToplistBlock(
            static fn(array $atts): string => '<aside class="rendered">OK</aside>',
            static fn(string $key, $default = null) => $default
        );

        $this->assertSame('<aside class="rendered">OK</aside>', $block->render(['toplistId' => 'x']));
    }
}
