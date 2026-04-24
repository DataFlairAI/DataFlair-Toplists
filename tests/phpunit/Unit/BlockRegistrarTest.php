<?php
/**
 * Phase 7 — pins BlockRegistrar behaviour.
 *
 * Responsibilities under test:
 *   - `register()` wires init + enqueue_block_editor_assets hooks.
 *   - `registerBlock()` calls WP's `register_block_type` with the correct
 *     block.json path and args.
 *   - `registerBlock()` falls back from build/ to src/ and silently no-ops
 *     if neither exists.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Block;

use DataFlair\Toplists\Block\BlockRegistrar;
use DataFlair\Toplists\Block\ToplistBlock;
use DataFlair\Toplists\Block\EditorAssets;
use DataFlair\Toplists\Tests\Block\BlockStubs;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/BlockTestStubs.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Block/EditorAssets.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Block/ToplistBlock.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Block/BlockRegistrar.php';

final class BlockRegistrarTest extends TestCase
{
    protected function setUp(): void
    {
        BlockStubs::reset();
    }

    public function test_register_wires_init_and_editor_assets_hooks(): void
    {
        $registrar = $this->makeRegistrar(__DIR__ . '/fixtures/block/');

        $registrar->register();

        $hooks = array_column(BlockStubs::$actions, 'hook');
        $this->assertContains('init', $hooks, 'must hook into init for register_block_type');
        $this->assertContains('enqueue_block_editor_assets', $hooks, 'must hook editor assets enqueue');
    }

    public function test_register_block_registers_with_built_block_json(): void
    {
        $fixture = $this->writeFixture('build/block.json', '{"name":"dataflair-toplists/toplist"}');
        $registrar = $this->makeRegistrar($fixture);

        $registrar->registerBlock();

        $this->assertCount(1, BlockStubs::$registered);
        $this->assertSame($fixture . 'build/block.json', BlockStubs::$registered[0]['block_json']);
        $this->assertSame('9.9.9', BlockStubs::$registered[0]['args']['version']);
        $this->assertIsCallable(BlockStubs::$registered[0]['args']['render_callback']);
    }

    public function test_register_block_falls_back_to_src_block_json(): void
    {
        $fixture = $this->writeFixture('src/block.json', '{"name":"dataflair-toplists/toplist"}');
        $registrar = $this->makeRegistrar($fixture);

        $registrar->registerBlock();

        $this->assertCount(1, BlockStubs::$registered);
        $this->assertSame($fixture . 'src/block.json', BlockStubs::$registered[0]['block_json']);
    }

    public function test_register_block_noop_when_no_block_json_found(): void
    {
        $fixture = sys_get_temp_dir() . '/' . uniqid('df-block-empty-', true) . '/';
        @mkdir($fixture, 0700, true);
        $registrar = $this->makeRegistrar($fixture);

        $registrar->registerBlock();

        $this->assertSame([], BlockStubs::$registered, 'must not register when neither block.json exists');
    }

    private function makeRegistrar(string $pluginDir): BlockRegistrar
    {
        $block = new ToplistBlock(
            static fn(array $atts): string => '<div>shortcode</div>',
            static fn(string $key, $default = null) => $default
        );
        $assets = new EditorAssets('http://example/plugin/', '9.9.9');
        return new BlockRegistrar($block, $assets, $pluginDir, '9.9.9');
    }

    private function writeFixture(string $relPath, string $contents): string
    {
        $root = sys_get_temp_dir() . '/' . uniqid('df-block-', true) . '/';
        @mkdir($root, 0700, true);
        @mkdir($root . dirname($relPath), 0700, true);
        file_put_contents($root . $relPath, $contents);
        return $root;
    }
}
