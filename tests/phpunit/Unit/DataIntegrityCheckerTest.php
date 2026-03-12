<?php
/**
 * Unit tests for DataFlair_DataIntegrityChecker
 *
 * Tests the validator in isolation — no WordPress, no DB, no HTTP.
 * All 20 tests specified in the Phase 4 brief are covered here.
 */

use PHPUnit\Framework\TestCase;

class DataIntegrityCheckerTest extends TestCase {

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Load the complete fixture and return its 'data' key.
     */
    private function completeFixture(): array {
        $path = __DIR__ . '/../fixtures/api-toplist-complete.json';
        $raw  = json_decode(file_get_contents($path), true);
        return $raw['data'];
    }

    /**
     * Build a minimal valid item at a given position.
     */
    private function validItem(int $position, array $overrides = []): array {
        $item = [
            'type'     => 'topListItem',
            'id'       => 100 + $position,
            'position' => $position,
            'isLocked' => false,
            'dealId'   => null,
            'brand'    => [
                'type'               => 'brand',
                'id'                 => 200 + $position,
                'name'               => "Casino {$position}",
                'slug'               => "casino-{$position}",
                'rating'             => 4.5,
                'logo'               => [
                    'rectangular'    => "https://cdn.example.com/{$position}-rect.png",
                    'square'         => "https://cdn.example.com/{$position}-sq.png",
                    'backgroundColor' => '#000000',
                ],
                'licenses'           => ['MGA'],
                'paymentMethods'     => ['Visa'],
                'restrictedCountries' => [],
            ],
            'offer' => [
                'type'                      => 'offer',
                'id'                        => 300 + $position,
                'offerTypeId'               => 1,
                'offerTypeName'             => 'Welcome Bonus',
                'offerText'                 => "100% up to \${$position}00",
                'currencies'                => ['USD'],
                'has_free_spins'            => false,
                'bonus_wagering_requirement' => 35,
                'bonus_expiry_date'         => null,
                'bonus_code'                => null,
                'minimum_deposit'           => 20,
                'max_payout'                => null,
                'max_bonus_amount'          => 500,
                'is_sticky_bonus'           => false,
                'minimum_odds'              => null,
                'free_bet_value'            => null,
                'stake_returned'            => null,
                'bet_type'                  => null,
                'tournament_ticket_value'   => null,
                'rakeback_percentage'       => null,
                'free_tickets'              => null,
                'geos' => [
                    'countries' => ['US'],
                    'markets'   => [],
                ],
                'trackers' => [
                    [
                        'id'           => 400 + $position,
                        'campaignName' => "Campaign {$position}",
                        'trackerLink'  => "https://track.example.com/go/{$position}",
                        'tcLink'       => "https://example.com/tc",
                        'pageType'     => 'homepage',
                        'geos'         => [
                            'countries' => ['US'],
                            'markets'   => [],
                        ],
                    ],
                ],
            ],
        ];

        return array_merge($item, $overrides);
    }

    /**
     * Build a minimal valid toplist with given items.
     */
    private function validToplist(array $items = []): array {
        return [
            'type'          => 'toplist',
            'id'            => 42,
            'name'          => 'Test Toplist',
            'status'        => 'published',
            'locked'        => false,
            'version'       => '2026-03-01T12:00:00+00:00',
            'slug'          => 'test-toplist',
            'currentPeriod' => '2026-03',
            'publishedAt'   => '2026-03-01T12:00:00+00:00',
            'shortcode'     => '[dataflair_toplist id="42"]',
            'createdAt'     => '2025-01-01T00:00:00+00:00',
            'updatedAt'     => '2026-03-01T12:00:00+00:00',
            'template'      => [
                'type'                   => 'listTemplate',
                'id'                     => 1,
                'name'                   => 'Casino Standard',
                'productTypeId'          => 1,
                'productType'            => 'Casino',
                'listClassificationTypeId' => 1,
                'listClassificationType' => 'Best',
            ],
            'site' => [
                'id'     => 1,
                'domain' => 'example.com',
            ],
            'geo' => [
                'geo_type' => 'country',
                'name'     => 'United States',
            ],
            'items' => $items ?: [$this->validItem(1)],
        ];
    }

    private function containsWarning(array $warnings, string $fragment): bool {
        foreach ($warnings as $w) {
            if (str_contains($w, $fragment)) {
                return true;
            }
        }
        return false;
    }

    // ── Tests 1-20 ───────────────────────────────────────────────────────────

    /** Test 1: Complete valid toplist → 0 warnings, correct counts */
    public function test_complete_valid_toplist_has_no_warnings(): void {
        $fixture = $this->completeFixture();
        $result  = DataFlair_DataIntegrityChecker::validate($fixture);

        $this->assertEmpty($result['warnings'], 'Expected 0 warnings for complete fixture: ' . implode('; ', $result['warnings']));
        $this->assertSame(5, $result['item_count']);
        $this->assertSame(2, $result['locked_count']); // items 1 and 2 are locked
    }

    /** Test 2: Missing offer geos entirely → warning "offer geos is NULL" */
    public function test_missing_offer_geos_produces_null_warning(): void {
        $item        = $this->validItem(1);
        $item['offer']['geos'] = null;
        $toplist     = $this->validToplist([$item]);

        $result = DataFlair_DataIntegrityChecker::validate($toplist);

        $this->assertTrue(
            $this->containsWarning($result['warnings'], 'offer geos is NULL'),
            'Expected "offer geos is NULL" warning. Got: ' . implode('; ', $result['warnings'])
        );
    }

    /** Test 3: Empty offer geos arrays → warning "offer has empty geos" */
    public function test_empty_offer_geos_arrays_produce_warning(): void {
        $item                          = $this->validItem(1);
        $item['offer']['geos']         = ['countries' => [], 'markets' => []];
        $toplist                       = $this->validToplist([$item]);

        $result = DataFlair_DataIntegrityChecker::validate($toplist);

        $this->assertTrue(
            $this->containsWarning($result['warnings'], 'offer has empty geos'),
            'Expected "offer has empty geos" warning. Got: ' . implode('; ', $result['warnings'])
        );
    }

    /** Test 4: offer geos.countries is string → warning "not an array" */
    public function test_offer_geos_countries_is_string_produces_warning(): void {
        $item                                    = $this->validItem(1);
        $item['offer']['geos']['countries']      = 'US';
        $toplist                                 = $this->validToplist([$item]);

        $result = DataFlair_DataIntegrityChecker::validate($toplist);

        $this->assertTrue(
            $this->containsWarning($result['warnings'], 'not an array'),
            'Expected "not an array" warning. Got: ' . implode('; ', $result['warnings'])
        );
    }

    /** Test 5: Missing brand object → warning "missing brand object" */
    public function test_missing_brand_object_produces_warning(): void {
        $item            = $this->validItem(1);
        $item['brand']   = null;
        $toplist         = $this->validToplist([$item]);

        $result = DataFlair_DataIntegrityChecker::validate($toplist);

        $this->assertTrue(
            $this->containsWarning($result['warnings'], 'missing brand object'),
            'Expected "missing brand object" warning. Got: ' . implode('; ', $result['warnings'])
        );
    }

    /** Test 6: Brand missing logo → warning "brand missing logo" */
    public function test_brand_missing_logo_produces_warning(): void {
        $item                 = $this->validItem(1);
        $item['brand']['logo'] = null;
        $toplist              = $this->validToplist([$item]);

        $result = DataFlair_DataIntegrityChecker::validate($toplist);

        $this->assertTrue(
            $this->containsWarning($result['warnings'], 'brand missing logo'),
            'Expected "brand missing logo" warning. Got: ' . implode('; ', $result['warnings'])
        );
    }

    /** Test 7: Brand logo missing rectangular URL → warning "brand logo missing rectangular URL" */
    public function test_brand_logo_missing_rectangular_produces_warning(): void {
        $item                             = $this->validItem(1);
        $item['brand']['logo']            = ['square' => 'https://cdn.example.com/sq.png', 'backgroundColor' => '#000'];
        $toplist                          = $this->validToplist([$item]);

        $result = DataFlair_DataIntegrityChecker::validate($toplist);

        $this->assertTrue(
            $this->containsWarning($result['warnings'], 'brand logo missing rectangular URL'),
            'Expected "brand logo missing rectangular URL" warning. Got: ' . implode('; ', $result['warnings'])
        );
    }

    /** Test 8: Missing offer object → warning "missing offer object" */
    public function test_missing_offer_object_produces_warning(): void {
        $item            = $this->validItem(1);
        $item['offer']   = null;
        $toplist         = $this->validToplist([$item]);

        $result = DataFlair_DataIntegrityChecker::validate($toplist);

        $this->assertTrue(
            $this->containsWarning($result['warnings'], 'missing offer object'),
            'Expected "missing offer object" warning. Got: ' . implode('; ', $result['warnings'])
        );
    }

    /** Test 9: Offer missing offerText → warning "offer missing offerText" */
    public function test_offer_missing_offer_text_produces_warning(): void {
        $item                          = $this->validItem(1);
        $item['offer']['offerText']    = '';
        $toplist                       = $this->validToplist([$item]);

        $result = DataFlair_DataIntegrityChecker::validate($toplist);

        $this->assertTrue(
            $this->containsWarning($result['warnings'], 'offer missing offerText'),
            'Expected "offer missing offerText" warning. Got: ' . implode('; ', $result['warnings'])
        );
    }

    /** Test 10: Offer missing currencies → warning "offer missing currencies" */
    public function test_offer_missing_currencies_produces_warning(): void {
        $item                       = $this->validItem(1);
        $item['offer']['currencies'] = [];
        $toplist                    = $this->validToplist([$item]);

        $result = DataFlair_DataIntegrityChecker::validate($toplist);

        $this->assertTrue(
            $this->containsWarning($result['warnings'], 'offer missing currencies'),
            'Expected "offer missing currencies" warning. Got: ' . implode('; ', $result['warnings'])
        );
    }

    /** Test 11: Offer has 0 trackers → warning "offer has 0 trackers" */
    public function test_offer_zero_trackers_produces_warning(): void {
        $item                     = $this->validItem(1);
        $item['offer']['trackers'] = [];
        $toplist                  = $this->validToplist([$item]);

        $result = DataFlair_DataIntegrityChecker::validate($toplist);

        $this->assertTrue(
            $this->containsWarning($result['warnings'], 'offer has 0 trackers'),
            'Expected "offer has 0 trackers" warning. Got: ' . implode('; ', $result['warnings'])
        );
    }

    /** Test 12: Tracker missing trackerLink → specific warning */
    public function test_tracker_missing_tracker_link_produces_warning(): void {
        $item                                       = $this->validItem(1);
        $item['offer']['trackers'][0]['trackerLink'] = '';
        $toplist                                    = $this->validToplist([$item]);

        $result = DataFlair_DataIntegrityChecker::validate($toplist);

        $this->assertTrue(
            $this->containsWarning($result['warnings'], 'missing trackerLink'),
            'Expected "missing trackerLink" warning. Got: ' . implode('; ', $result['warnings'])
        );
    }

    /** Test 13: Tracker missing tcLink → specific warning */
    public function test_tracker_missing_tc_link_produces_warning(): void {
        $item                                    = $this->validItem(1);
        $item['offer']['trackers'][0]['tcLink']  = '';
        $toplist                                 = $this->validToplist([$item]);

        $result = DataFlair_DataIntegrityChecker::validate($toplist);

        $this->assertTrue(
            $this->containsWarning($result['warnings'], 'missing tcLink'),
            'Expected "missing tcLink" warning. Got: ' . implode('; ', $result['warnings'])
        );
    }

    /** Test 14: Tracker missing geos object → specific warning */
    public function test_tracker_missing_geos_produces_warning(): void {
        $item                                  = $this->validItem(1);
        $item['offer']['trackers'][0]['geos']  = null;
        $toplist                               = $this->validToplist([$item]);

        $result = DataFlair_DataIntegrityChecker::validate($toplist);

        $this->assertTrue(
            $this->containsWarning($result['warnings'], 'missing geos object'),
            'Expected "missing geos object" warning. Got: ' . implode('; ', $result['warnings'])
        );
    }

    /** Test 15: Missing snapshot fields → a warning for each */
    public function test_missing_snapshot_fields_produce_warnings(): void {
        $toplist = $this->validToplist();
        unset($toplist['slug'], $toplist['currentPeriod']);

        $result = DataFlair_DataIntegrityChecker::validate($toplist);

        $this->assertTrue(
            $this->containsWarning($result['warnings'], 'missing snapshot field: slug'),
            'Expected slug warning. Got: ' . implode('; ', $result['warnings'])
        );
        $this->assertTrue(
            $this->containsWarning($result['warnings'], 'missing snapshot field: currentPeriod'),
            'Expected currentPeriod warning. Got: ' . implode('; ', $result['warnings'])
        );
    }

    /** Test 16: Locked items counted correctly */
    public function test_locked_items_counted_correctly(): void {
        $items = [];
        for ($i = 1; $i <= 10; $i++) {
            $item             = $this->validItem($i);
            $item['isLocked'] = in_array($i, [2, 5, 8]); // 3 locked
            $item['dealId']   = $item['isLocked'] ? "deal-00{$i}" : null;
            $items[]          = $item;
        }

        $toplist = $this->validToplist($items);
        $result  = DataFlair_DataIntegrityChecker::validate($toplist);

        $this->assertSame(10, $result['item_count']);
        $this->assertSame(3, $result['locked_count']);
    }

    /** Test 17: Geo coverage collected across all items */
    public function test_geo_coverage_collected_from_all_items(): void {
        $item1 = $this->validItem(1);
        $item1['offer']['geos'] = ['countries' => ['US', 'CA'], 'markets' => ['NORTH_AMERICA']];

        $item2 = $this->validItem(2);
        $item2['offer']['geos'] = ['countries' => ['GB'], 'markets' => ['EU']];

        $toplist = $this->validToplist([$item1, $item2]);
        $result  = DataFlair_DataIntegrityChecker::validate($toplist);

        $this->assertContains('US', $result['geo_coverage']);
        $this->assertContains('CA', $result['geo_coverage']);
        $this->assertContains('NORTH_AMERICA', $result['geo_coverage']);
        $this->assertContains('GB', $result['geo_coverage']);
        $this->assertContains('EU', $result['geo_coverage']);
    }

    /** Test 18: Multiple warnings on same item — all collected, none dropped */
    public function test_multiple_warnings_on_same_item_all_collected(): void {
        $item                          = $this->validItem(1);
        $item['offer']['geos']         = null;       // → geos NULL warning
        $item['offer']['currencies']   = [];          // → missing currencies
        $item['offer']['trackers']     = [];          // → 0 trackers
        $toplist                       = $this->validToplist([$item]);

        $result = DataFlair_DataIntegrityChecker::validate($toplist);

        // All three issues on position 1 should be present
        $this->assertTrue($this->containsWarning($result['warnings'], 'offer geos is NULL'));
        $this->assertTrue($this->containsWarning($result['warnings'], 'offer missing currencies'));
        $this->assertTrue($this->containsWarning($result['warnings'], 'offer has 0 trackers'));
        $this->assertGreaterThanOrEqual(3, count($result['warnings']));
    }

    /** Test 19: Empty items array → counts are zero, no item warnings */
    public function test_empty_items_array_produces_zero_counts(): void {
        $toplist           = $this->validToplist([]);
        $toplist['items']  = [];

        $result = DataFlair_DataIntegrityChecker::validate($toplist);

        $this->assertSame(0, $result['item_count']);
        $this->assertSame(0, $result['locked_count']);
        // Should only have top-level warnings (if any), not item warnings
        foreach ($result['warnings'] as $w) {
            $this->assertStringNotContainsString('Position', $w,
                'No position-level warnings expected for empty items. Got: ' . $w);
        }
    }

    /** Test 20: Missing top-level fields each produce a warning */
    public function test_missing_top_level_fields_produce_warnings(): void {
        $toplist = $this->validToplist();
        unset($toplist['name'], $toplist['template'], $toplist['geo']);

        $result = DataFlair_DataIntegrityChecker::validate($toplist);

        $this->assertTrue(
            $this->containsWarning($result['warnings'], 'Toplist missing top-level field: name'),
            'Expected "name" warning. Got: ' . implode('; ', $result['warnings'])
        );
        $this->assertTrue(
            $this->containsWarning($result['warnings'], 'Toplist missing top-level field: template'),
            'Expected "template" warning. Got: ' . implode('; ', $result['warnings'])
        );
        $this->assertTrue(
            $this->containsWarning($result['warnings'], 'Toplist missing top-level field: geo'),
            'Expected "geo" warning. Got: ' . implode('; ', $result['warnings'])
        );
    }
}
