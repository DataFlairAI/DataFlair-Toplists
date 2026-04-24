<?php
/**
 * Unit test for TableRenderer — Phase 4 accordion-table renderer.
 *
 * Exercises the inline accordion template directly. No DB, no HTTP, no
 * template-file include. Verifies the structural invariants the block
 * editor debug layout depends on:
 *   - Accordion wrapper is emitted.
 *   - Stale notice fires only when `isStale = true`.
 *   - Title block renders only when non-empty.
 *   - One `<details>` per item with the position + brand name in the summary.
 *   - Pros/cons data from the ToplistTableVM is rendered via the
 *     `resolve_pros_cons_for_table_item()` trait contract.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit;

use DataFlair\Toplists\Frontend\Render\TableRenderer;
use DataFlair\Toplists\Frontend\Render\ViewModels\ToplistTableVM;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Frontend/Render/ProsConsResolver.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Frontend/Render/TableRendererInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Frontend/Render/TableRenderer.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Frontend/Render/ViewModels/ToplistTableVM.php';

if (!function_exists('esc_html')) {
    function esc_html($value) { return (string) $value; }
}

final class TableRendererTest extends TestCase
{
    private TableRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new TableRenderer();
    }

    public function test_emits_accordion_wrapper_and_title(): void
    {
        $vm = new ToplistTableVM(
            [['brand' => ['name' => 'Acme'], 'position' => 1]],
            'Best Casinos 2026',
            false,
            0
        );

        $html = $this->renderer->render($vm);

        $this->assertStringContainsString('dataflair-toplist-accordion', $html);
        $this->assertStringContainsString('Best Casinos 2026', $html);
    }

    public function test_omits_title_block_when_empty(): void
    {
        $vm = new ToplistTableVM(
            [['brand' => ['name' => 'Acme'], 'position' => 1]],
            '',
            false,
            0
        );

        $html = $this->renderer->render($vm);

        $this->assertStringNotContainsString('dataflair-title', $html);
    }

    public function test_stale_notice_fires_only_when_is_stale(): void
    {
        $stale = new ToplistTableVM([], 't', true, 1710000000);
        $fresh = new ToplistTableVM([], 't', false, 1710000000);

        $this->assertStringContainsString('dataflair-notice', $this->renderer->render($stale));
        $this->assertStringNotContainsString('dataflair-notice', $this->renderer->render($fresh));
    }

    public function test_renders_one_details_block_per_item(): void
    {
        $items = [
            ['brand' => ['name' => 'Alpha'], 'position' => 1],
            ['brand' => ['name' => 'Bravo'], 'position' => 2],
            ['brand' => ['name' => 'Charlie'], 'position' => 3],
        ];
        $vm = new ToplistTableVM($items, 't', false, 0);

        $html = $this->renderer->render($vm);

        $this->assertSame(3, substr_count($html, '<details'));
        $this->assertStringContainsString('Alpha', $html);
        $this->assertStringContainsString('Bravo', $html);
        $this->assertStringContainsString('Charlie', $html);
    }

    public function test_uses_brand_name_fallback_when_missing(): void
    {
        $vm = new ToplistTableVM(
            [['position' => 1]],
            't',
            false,
            0
        );

        $html = $this->renderer->render($vm);

        $this->assertStringContainsString('Unknown Brand', $html);
    }

    public function test_emits_pros_and_cons_from_vm_pros_cons_data(): void
    {
        $items = [[
            'brand' => ['name' => 'Acme', 'id' => 1, 'slug' => 'acme'],
            'position' => 1,
        ]];
        $pros_cons_data = [
            'casino-brand-1' => [
                'pros' => ['Fast payouts', 'Large game library'],
                'cons' => ['Limited live support'],
            ],
        ];
        $vm = new ToplistTableVM($items, 't', false, 0, $pros_cons_data);

        $html = $this->renderer->render($vm);

        $this->assertStringContainsString('Fast payouts', $html);
        $this->assertStringContainsString('Large game library', $html);
        $this->assertStringContainsString('Limited live support', $html);
    }

    public function test_emits_offer_fields_from_offer_subarray(): void
    {
        $items = [[
            'brand' => ['name' => 'Acme'],
            'position' => 1,
            'offer' => [
                'offerText' => '100% up to $500',
                'bonus_code' => 'WELCOME100',
                'minimum_deposit' => '$10',
            ],
        ]];
        $vm = new ToplistTableVM($items, 't', false, 0);

        $html = $this->renderer->render($vm);

        $this->assertStringContainsString('100% up to $500', $html);
        $this->assertStringContainsString('WELCOME100', $html);
        $this->assertStringContainsString('$10', $html);
    }
}
