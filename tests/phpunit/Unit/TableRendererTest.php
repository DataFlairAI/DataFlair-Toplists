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

require_once DATAFLAIR_PLUGIN_DIR . 'tests/phpunit/Unit/TableRendererTestStubs.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Frontend/Render/ProsConsResolver.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Frontend/Render/TableRendererInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Frontend/Render/TableRenderer.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Frontend/Render/ViewModels/ToplistTableVM.php';
require_once __DIR__ . '/TableRendererTestStubs.php';

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

    public function test_resolves_pros_cons_via_slug_key_for_brand_name_with_accents_and_punctuation(): void
    {
        // Slug pinned against a live WordPress 6.9.4 install's real sanitize_title()
        // (verified via `wp eval-file`):
        //   "Björn's Casino & Café_Royale...Deluxe" => "bjorns-casino-cafe_royale-deluxe"
        // Exercises every divergence the fake sanitize_title() implementations used to
        // get wrong: accent transliteration (ö/é), ampersand deletion, apostrophe
        // deletion, underscore preservation, and consecutive-dash collapsing ("...").
        // No brand id/item id is set, so the resolver can only reach this override via
        // the `casino-slug-{slug}` key — a wrong slug means a lookup miss.
        $items = [[
            'brand' => ['name' => "Björn's Casino & Café_Royale...Deluxe"],
            'position' => 1,
        ]];
        $pros_cons_data = [
            'casino-slug-bjorns-casino-cafe_royale-deluxe' => [
                'pros' => ['Fast KYC'],
                'cons' => ['High wagering requirement'],
            ],
        ];
        $vm = new ToplistTableVM($items, 't', false, 0, $pros_cons_data);

        $html = $this->renderer->render($vm);

        $this->assertStringContainsString('Fast KYC', $html);
        $this->assertStringContainsString('High wagering requirement', $html);
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

    public function test_product_type_sourced_from_classification_types(): void
    {
        $items = [[
            'brand' => ['name' => 'Acme', 'classificationTypes' => ['Crypto Casino', 'Sportsbook']],
            'position' => 1,
        ]];
        $vm = new ToplistTableVM($items, 't', false, 0);

        $html = $this->renderer->render($vm);

        $this->assertStringContainsString('Crypto Casino, Sportsbook', $html);
    }

    public function test_emits_brand_taxonomy_fields(): void
    {
        $items = [[
            'brand' => [
                'name' => 'Acme',
                'restrictedCountries' => ['United States', 'France'],
                'gameTypes' => ['Slots', 'Live Casino'],
                'gameProviders' => ['NetEnt', 'Microgaming'],
                'languages' => [
                    'website' => ['English', 'German'],
                    'support' => ['English'],
                    'livechat' => [],
                ],
            ],
            'position' => 1,
        ]];
        $vm = new ToplistTableVM($items, 't', false, 0);

        $html = $this->renderer->render($vm);

        $this->assertStringContainsString('United States, France', $html);
        $this->assertStringContainsString('Slots, Live Casino', $html);
        $this->assertStringContainsString('NetEnt, Microgaming', $html);
        $this->assertStringContainsString('English, German', $html);
    }

    public function test_emits_offer_type_and_currencies(): void
    {
        $items = [[
            'brand' => ['name' => 'Acme'],
            'position' => 1,
            'offer' => [
                'offerTypeName' => 'Deposit Match',
                'currencies' => ['USD', 'EUR'],
            ],
        ]];
        $vm = new ToplistTableVM($items, 't', false, 0);

        $html = $this->renderer->render($vm);

        $this->assertStringContainsString('Deposit Match', $html);
        $this->assertStringContainsString('USD, EUR', $html);
    }

    public function test_emits_free_spins_when_present(): void
    {
        $items = [[
            'brand' => ['name' => 'Acme'],
            'position' => 1,
            'offer' => ['has_free_spins' => true, 'free_spin_count' => 50],
        ]];
        $vm = new ToplistTableVM($items, 't', false, 0);

        $html = $this->renderer->render($vm);

        $this->assertStringContainsString('Yes (50)', $html);
    }

    public function test_omits_free_spins_row_when_absent(): void
    {
        $items = [[
            'brand' => ['name' => 'Acme'],
            'position' => 1,
            'offer' => ['has_free_spins' => false],
        ]];
        $vm = new ToplistTableVM($items, 't', false, 0);

        $html = $this->renderer->render($vm);

        $this->assertStringNotContainsString('Free Spins', $html);
    }

    public function test_emits_sticky_bonus_and_expiry(): void
    {
        $items = [[
            'brand' => ['name' => 'Acme'],
            'position' => 1,
            'offer' => ['is_sticky_bonus' => true, 'bonus_expiry_date' => '2026-12-31'],
        ]];
        $vm = new ToplistTableVM($items, 't', false, 0);

        $html = $this->renderer->render($vm);

        $this->assertStringContainsString('Sticky Bonus', $html);
        $this->assertStringContainsString('Yes', $html);
        $this->assertStringContainsString('2026-12-31', $html);
    }

    public function test_emits_max_bonus_amount(): void
    {
        $items = [[
            'brand' => ['name' => 'Acme'],
            'position' => 1,
            'offer' => ['max_bonus_amount' => '500'],
        ]];
        $vm = new ToplistTableVM($items, 't', false, 0);

        $html = $this->renderer->render($vm);

        $this->assertStringContainsString('Max Bonus Amount', $html);
        $this->assertStringContainsString('500', $html);
    }

    public function test_emits_sportsbook_fields_only_when_present(): void
    {
        $withSportsbook = new ToplistTableVM([[
            'brand' => ['name' => 'Acme'],
            'position' => 1,
            'offer' => [
                'minimum_odds' => '1.5',
                'free_bet_value' => '20',
                'stake_returned' => true,
                'bet_type' => 'single',
            ],
        ]], 't', false, 0);

        $withoutSportsbook = new ToplistTableVM([[
            'brand' => ['name' => 'Acme'],
            'position' => 1,
        ]], 't', false, 0);

        $htmlWith = $this->renderer->render($withSportsbook);
        $htmlWithout = $this->renderer->render($withoutSportsbook);

        $this->assertStringContainsString('Minimum Odds', $htmlWith);
        $this->assertStringContainsString('Free Bet Value', $htmlWith);
        $this->assertStringContainsString('Stake Returned', $htmlWith);
        $this->assertStringContainsString('Bet Type', $htmlWith);

        $this->assertStringNotContainsString('Minimum Odds', $htmlWithout);
        $this->assertStringNotContainsString('Free Bet Value', $htmlWithout);
        $this->assertStringNotContainsString('Stake Returned', $htmlWithout);
        $this->assertStringNotContainsString('Bet Type', $htmlWithout);
    }

    public function test_emits_poker_fields_only_when_present(): void
    {
        $withPoker = new ToplistTableVM([[
            'brand' => ['name' => 'Acme'],
            'position' => 1,
            'offer' => [
                'tournament_ticket_value' => '25',
                'rakeback_percentage' => '10',
                'free_tickets' => '3',
            ],
        ]], 't', false, 0);

        $withoutPoker = new ToplistTableVM([[
            'brand' => ['name' => 'Acme'],
            'position' => 1,
        ]], 't', false, 0);

        $htmlWith = $this->renderer->render($withPoker);
        $htmlWithout = $this->renderer->render($withoutPoker);

        $this->assertStringContainsString('Tournament Ticket Value', $htmlWith);
        $this->assertStringContainsString('Rakeback', $htmlWith);
        $this->assertStringContainsString('Free Tickets', $htmlWith);

        $this->assertStringNotContainsString('Tournament Ticket Value', $htmlWithout);
        $this->assertStringNotContainsString('Rakeback', $htmlWithout);
        $this->assertStringNotContainsString('Free Tickets', $htmlWithout);
    }

    public function test_no_longer_emits_dead_payout_time_or_games_count_labels(): void
    {
        $vm = new ToplistTableVM(
            [['brand' => ['name' => 'Acme'], 'position' => 1]],
            't',
            false,
            0
        );

        $html = $this->renderer->render($vm);

        $this->assertStringNotContainsString('Payout Time', $html);
        $this->assertStringNotContainsString('Games Count', $html);
    }
}
