<?php
/**
 * Phase 9.12 — Pins Frontend\Shortcode\ShortcodeRegistrar wiring.
 *
 * The registrar is intentionally minimal: it accepts a callable and
 * forwards it to `add_shortcode('dataflair_toplist', …)`. We assert:
 *   - The shortcode tag stays `dataflair_toplist` (public contract).
 *   - The exact callable we passed in is what WP receives. No wrapping,
 *     no rebinding — that would change closure timing and break the
 *     deferred renderer-filter resolution Phase 9.12 depends on.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Frontend;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DataFlair\Toplists\Frontend\Shortcode\ShortcodeRegistrar;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Frontend/Shortcode/ShortcodeRegistrar.php';

final class ShortcodeRegistrarTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_register_calls_add_shortcode_with_dataflair_toplist_tag(): void
    {
        $captured = [];
        Functions\when('add_shortcode')->alias(function (string $tag, $callback) use (&$captured): void {
            $captured[$tag] = $callback;
        });

        $callback = static function (): string { return 'hello'; };

        (new ShortcodeRegistrar($callback))->register();

        $this->assertArrayHasKey('dataflair_toplist', $captured);
        $this->assertSame($callback, $captured['dataflair_toplist']);
    }

    public function test_accepts_method_array_callable(): void
    {
        $captured = null;
        Functions\when('add_shortcode')->alias(function (string $tag, $callback) use (&$captured): void {
            $captured = $callback;
        });

        $target = new class {
            public function render($atts): string
            {
                return is_array($atts) ? 'arr' : 'str';
            }
        };

        (new ShortcodeRegistrar([$target, 'render']))->register();

        $this->assertIsArray($captured);
        $this->assertSame($target, $captured[0]);
        $this->assertSame('render', $captured[1]);
    }
}
