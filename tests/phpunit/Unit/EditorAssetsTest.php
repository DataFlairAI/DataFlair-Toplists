<?php
/**
 * Phase 7 — pins EditorAssets enqueue behaviour.
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
        (new EditorAssets('http://example/plugin/', '9.9.9'))->enqueue();

        $this->assertCount(1, BlockStubs::$enqueuedStyles);
        $style = BlockStubs::$enqueuedStyles[0];
        $this->assertSame('dataflair-toplist-editor',           $style['handle']);
        $this->assertSame('http://example/plugin/assets/editor.css', $style['src']);
        $this->assertSame([],                                   $style['deps']);
        $this->assertSame('9.9.9',                              $style['ver']);
    }
}
