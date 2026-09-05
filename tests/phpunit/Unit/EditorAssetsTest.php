<?php
/**
 * Phase 7 — pins EditorAssets enqueue behaviour.
 *
 * Editor CSS must load inside the iframed block canvas (via enqueue_block_assets
 * + is_admin). Front-end must skip so theme styles remain the sole card look.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Block;

use DataFlair\Toplists\Block\EditorAssets;
use DataFlair\Toplists\Tests\Block\BlockStubs;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/BlockTestStubs.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Block/EditorAssets.php';

final class EditorAssetsTest extends TestCase
{
    protected function setUp(): void
    {
        BlockStubs::reset();
    }

    public function test_enqueue_registers_editor_stylesheet_with_version(): void
    {
        BlockStubs::$isAdmin = true;

        (new EditorAssets('http://example/plugin/', '9.9.9'))->enqueue();

        $this->assertCount(1, BlockStubs::$enqueuedStyles);
        $style = BlockStubs::$enqueuedStyles[0];
        $this->assertSame('dataflair-toplist-editor',           $style['handle']);
        $this->assertSame('http://example/plugin/assets/editor.css', $style['src']);
        $this->assertSame([],                                   $style['deps']);
        $this->assertSame('9.9.9',                              $style['ver']);
    }

    public function test_enqueue_skips_on_front_end(): void
    {
        BlockStubs::$isAdmin = false;

        (new EditorAssets('http://example/plugin/', '9.9.9'))->enqueue();

        $this->assertSame([], BlockStubs::$enqueuedStyles, 'editor.css must not load on the public front-end');
    }
}
