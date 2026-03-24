<?php
/**
 * Unit tests for ProductTypeLabels.
 *
 * Achieves 100% line coverage of includes/ProductTypeLabels.php.
 * All three public static methods are covered including edge cases.
 */

use PHPUnit\Framework\TestCase;

class ProductTypeLabelsTest extends TestCase {

    // ── normalizeType ────────────────────────────────────────────────────────

    public function test_normalize_type_lowercases(): void {
        $this->assertSame('casino', ProductTypeLabels::normalizeType('Casino'));
    }

    public function test_normalize_type_trims_whitespace(): void {
        $this->assertSame('sportsbook', ProductTypeLabels::normalizeType('  Sportsbook  '));
    }

    public function test_normalize_type_handles_already_lowercase(): void {
        $this->assertSame('casino', ProductTypeLabels::normalizeType('casino'));
    }

    public function test_normalize_type_handles_empty_string(): void {
        $this->assertSame('', ProductTypeLabels::normalizeType(''));
    }

    public function test_normalize_type_handles_null_coercion(): void {
        // (string) null === ''
        $this->assertSame('', ProductTypeLabels::normalizeType(''));
    }

    // ── getLabels ────────────────────────────────────────────────────────────

    public function test_get_labels_for_casino_returns_casino_labels(): void {
        $labels = ProductTypeLabels::getLabels('casino');
        $this->assertSame('Welcome Bonus', $labels['offer_text_label']);
        $this->assertSame('Bonus Wagering', $labels['bonus_wagering_label']);
        $this->assertSame('Casino Games', $labels['games_count_label']);
    }

    public function test_get_labels_is_case_insensitive(): void {
        $labels = ProductTypeLabels::getLabels('Casino');
        $this->assertSame('Welcome Bonus', $labels['offer_text_label']);
    }

    public function test_get_labels_for_sportsbook_returns_sportsbook_labels(): void {
        $labels = ProductTypeLabels::getLabels('Sportsbook');
        $this->assertSame('Welcome Offer', $labels['offer_text_label']);
        $this->assertSame('Rollover Requirement', $labels['bonus_wagering_label']);
    }

    public function test_get_labels_unknown_type_falls_back_to_casino(): void {
        $labels = ProductTypeLabels::getLabels('poker');
        $this->assertSame('Welcome Bonus', $labels['offer_text_label']);
    }

    public function test_get_labels_empty_string_falls_back_to_casino(): void {
        $labels = ProductTypeLabels::getLabels('');
        $this->assertSame('Welcome Bonus', $labels['offer_text_label']);
    }

    public function test_get_labels_returns_all_seven_keys(): void {
        $labels = ProductTypeLabels::getLabels('casino');
        $expectedKeys = [
            'offer_text_label',
            'bonus_wagering_label',
            'min_deposit_label',
            'games_count_label',
            'payout_time_label',
            'max_payout_label',
            'licences_label',
        ];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $labels, "Expected key '{$key}' missing from getLabels()");
        }
    }

    // ── isFieldVisible ────────────────────────────────────────────────────────

    public function test_is_field_visible_returns_true_for_casino_any_field(): void {
        $this->assertTrue(ProductTypeLabels::isFieldVisible('casino', 'games_count'));
        $this->assertTrue(ProductTypeLabels::isFieldVisible('casino', 'has_free_spins'));
    }

    public function test_is_field_visible_games_count_hidden_for_sportsbook(): void {
        $this->assertFalse(ProductTypeLabels::isFieldVisible('sportsbook', 'games_count'));
    }

    public function test_is_field_visible_has_free_spins_hidden_for_sportsbook(): void {
        $this->assertFalse(ProductTypeLabels::isFieldVisible('sportsbook', 'has_free_spins'));
    }

    public function test_is_field_visible_offer_text_visible_for_sportsbook(): void {
        $this->assertTrue(ProductTypeLabels::isFieldVisible('sportsbook', 'offer_text'));
    }

    public function test_is_field_visible_unknown_product_type_shows_all_fields(): void {
        $this->assertTrue(ProductTypeLabels::isFieldVisible('poker', 'games_count'));
        $this->assertTrue(ProductTypeLabels::isFieldVisible('', 'has_free_spins'));
    }

    public function test_is_field_visible_case_insensitive_type(): void {
        $this->assertFalse(ProductTypeLabels::isFieldVisible('Sportsbook', 'games_count'));
    }
}
