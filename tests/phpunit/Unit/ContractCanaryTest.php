<?php
/**
 * API Contract Safety P3 — pins ContractCanary hard-failure detection.
 *
 * The canary must catch render-critical field renames/retypes in the page-1
 * payload (bucket-1 silent drift) while never false-positiving on legitimate
 * partial data: null values keep their keys, and collective checks below the
 * MIN_SAMPLE threshold are inconclusive by design.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Sync;

use DataFlair\Toplists\Sync\ContractCanary;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/ContractCanary.php';

final class ContractCanaryTest extends TestCase
{
    private ContractCanary $canary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->canary = new ContractCanary();
    }

    /** A healthy toplist item as the v1 contract ships it today. */
    private function item(array $offerOverrides = [], array $itemOverrides = []): array
    {
        $offer = array_merge([
            'offerText'  => '100% up to $500',
            'bonus_code' => 'WELCOME',
            'trackers'   => [
                ['trackerLink' => 'https://t.example.com/c/1', 'campaignName' => 'Main'],
            ],
        ], $offerOverrides);

        return array_merge([
            'position' => 1,
            'brandId'  => 42,
            'brand'    => ['id' => 42, 'name' => 'Betway'],
            'offer'    => $offer,
        ], $itemOverrides);
    }

    private function toplist(array $items): array
    {
        return ['id' => 101, 'name' => 'Top 10 US Casinos', 'items' => $items];
    }

    public function test_empty_payload_is_inconclusive(): void
    {
        $this->assertNull($this->canary->assess([]));
    }

    public function test_healthy_payload_passes(): void
    {
        $payload = [$this->toplist([$this->item(), $this->item(), $this->item(), $this->item()])];
        $this->assertNull($this->canary->assess($payload));
    }

    public function test_toplist_without_items_key_passes(): void
    {
        // Bulk summaries without items must stay valid (small tenants, other
        // endpoints) — the sample threshold makes them inconclusive.
        $this->assertNull($this->canary->assess([['id' => 1, 'name' => 'A']]));
    }

    public function test_missing_toplist_id_is_hard_failure(): void
    {
        $failure = $this->canary->assess([['name' => 'A', 'items' => []]]);
        $this->assertStringContainsString('"id"', (string) $failure);
    }

    public function test_missing_toplist_name_is_hard_failure(): void
    {
        $failure = $this->canary->assess([['id' => 1, 'items' => []]]);
        $this->assertStringContainsString('"name"', (string) $failure);
    }

    public function test_items_retyped_to_string_is_hard_failure(): void
    {
        $failure = $this->canary->assess([['id' => 1, 'name' => 'A', 'items' => 'oops']]);
        $this->assertStringContainsString('"items"', (string) $failure);
    }

    public function test_offer_renamed_away_on_every_item_is_hard_failure(): void
    {
        $item = $this->item();
        unset($item['offer']);
        $item['promotion'] = ['offerText' => 'x'];

        $failure = $this->canary->assess([$this->toplist([$item, $item, $item])]);
        $this->assertStringContainsString('"offer"', (string) $failure);
    }

    public function test_offer_missing_on_some_items_only_is_not_a_failure(): void
    {
        $bare = $this->item();
        unset($bare['offer']);

        $payload = [$this->toplist([$this->item(), $bare, $this->item()])];
        $this->assertNull($this->canary->assess($payload));
    }

    public function test_below_sample_threshold_is_inconclusive(): void
    {
        $item = $this->item();
        unset($item['offer']);

        // Only two items: even with offer gone everywhere, stay quiet.
        $this->assertNull($this->canary->assess([$this->toplist([$item, $item])]));
    }

    public function test_brand_linkage_gone_everywhere_is_hard_failure(): void
    {
        $item = $this->item();
        unset($item['brand'], $item['brandId']);

        $failure = $this->canary->assess([$this->toplist([$item, $item, $item])]);
        $this->assertStringContainsString('brand', (string) $failure);
    }

    public function test_offer_text_key_removed_everywhere_is_hard_failure(): void
    {
        $item = $this->item(['offer_text' => 'renamed'], []);
        unset($item['offer']['offerText']);

        $failure = $this->canary->assess([$this->toplist([$item, $item, $item])]);
        $this->assertStringContainsString('"offerText"', (string) $failure);
    }

    public function test_null_offer_text_keeps_key_and_passes(): void
    {
        $payload = [$this->toplist([
            $this->item(['offerText' => null]),
            $this->item(['offerText' => null]),
            $this->item(['offerText' => null]),
        ])];
        $this->assertNull($this->canary->assess($payload));
    }

    public function test_trackers_retyped_is_hard_failure(): void
    {
        $item = $this->item(['trackers' => 'https://t.example.com/c/1']);

        $failure = $this->canary->assess([$this->toplist([$item, $item, $item])]);
        $this->assertStringContainsString('"trackers"', (string) $failure);
    }

    public function test_tracker_link_renamed_everywhere_is_hard_failure(): void
    {
        $item = $this->item(['trackers' => [['url' => 'https://t.example.com/c/1']]]);

        $failure = $this->canary->assess([$this->toplist([$item, $item, $item])]);
        $this->assertStringContainsString('"trackerLink"', (string) $failure);
    }

    public function test_items_without_trackers_are_inconclusive(): void
    {
        $payload = [$this->toplist([
            $this->item(['trackers' => []]),
            $this->item(['trackers' => []]),
            $this->item(['trackers' => []]),
        ])];
        $this->assertNull($this->canary->assess($payload));
    }
}
