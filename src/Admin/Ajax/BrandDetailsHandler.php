<?php
/**
 * Phase 9.6 (admin UX redesign) — Return full structured details for a brand accordion row.
 *
 * Reads the stored `data` JSON blob and returns every field needed by the
 * accordion: geos, licenses, payment methods, game types, restricted countries,
 * offers with their trackers/affiliate links.
 *
 * Input:  { api_brand_id: int }
 * Output: { success: true, data: { api_brand_id, name, top_geos, restricted_countries,
 *           licenses, payment_methods, game_types, product_types, offers } }
 *
 * Offer shape:
 *   { id, offer_text, bonus_code, product_type, geo_countries, trackers }
 * Tracker shape:
 *   { id, campaign_name, tracker_link, page_type }
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Ajax;

use DataFlair\Toplists\Admin\AjaxHandlerInterface;
use DataFlair\Toplists\Database\BrandsRepositoryInterface;

final class BrandDetailsHandler implements AjaxHandlerInterface
{
    public function __construct(private readonly BrandsRepositoryInterface $brands) {}

    public function handle(array $request): array
    {
        $id = isset($request['api_brand_id']) ? (int) $request['api_brand_id'] : 0;
        if ($id <= 0) {
            return ['success' => false, 'data' => ['message' => 'Invalid api_brand_id.']];
        }

        $row = $this->brands->findByApiBrandId($id);
        if ($row === null) {
            return ['success' => false, 'data' => ['message' => 'Brand not found.']];
        }

        $raw  = json_decode((string) ($row['data'] ?? ''), true);
        $data = is_array($raw) ? $raw : [];

        $top_geos = [];
        if (isset($data['topGeos']['countries']) && is_array($data['topGeos']['countries'])) {
            $top_geos = array_merge($top_geos, $data['topGeos']['countries']);
        }
        if (isset($data['topGeos']['markets']) && is_array($data['topGeos']['markets'])) {
            $top_geos = array_merge($top_geos, $data['topGeos']['markets']);
        }

        $restricted = isset($data['restrictedCountries']) && is_array($data['restrictedCountries'])
            ? $data['restrictedCountries'] : [];

        $licenses = isset($data['licenses']) && is_array($data['licenses'])
            ? $data['licenses'] : [];

        $payments = isset($data['paymentMethods']) && is_array($data['paymentMethods'])
            ? $data['paymentMethods']
            : (isset($data['payments']) && is_array($data['payments']) ? $data['payments'] : []);

        $game_types = isset($data['gameTypes']) && is_array($data['gameTypes'])
            ? $data['gameTypes'] : [];

        $product_types = isset($data['productTypes']) && is_array($data['productTypes'])
            ? $data['productTypes'] : [];

        $offers = [];
        if (isset($data['offers']) && is_array($data['offers'])) {
            foreach ($data['offers'] as $offer) {
                $geos = [];
                if (isset($offer['geos']['countries']) && is_array($offer['geos']['countries'])) {
                    $geos = array_merge($geos, $offer['geos']['countries']);
                }
                if (isset($offer['geos']['markets']) && is_array($offer['geos']['markets'])) {
                    $geos = array_merge($geos, $offer['geos']['markets']);
                }

                $trackers = [];
                if (isset($offer['trackers']) && is_array($offer['trackers'])) {
                    foreach ($offer['trackers'] as $t) {
                        $trackers[] = [
                            'id'            => (int) ($t['id'] ?? 0),
                            'campaign_name' => (string) ($t['campaignName'] ?? ''),
                            'tracker_link'  => (string) ($t['trackerLink'] ?? ''),
                            'page_type'     => (string) ($t['pageType'] ?? ''),
                        ];
                    }
                }

                $offers[] = [
                    'id'           => (int) ($offer['id'] ?? 0),
                    'offer_text'   => (string) ($offer['offerText'] ?? ''),
                    'bonus_code'   => isset($offer['bonusCode']) ? (string) $offer['bonusCode'] : null,
                    'product_type' => (string) ($offer['productType'] ?? ''),
                    'geo_countries' => $geos,
                    'trackers'     => $trackers,
                ];
            }
        }

        return ['success' => true, 'data' => [
            'api_brand_id'        => $id,
            'name'                => (string) ($row['name'] ?? ''),
            'top_geos'            => $top_geos,
            'restricted_countries' => $restricted,
            'licenses'            => $licenses,
            'payment_methods'     => $payments,
            'game_types'          => $game_types,
            'product_types'       => $product_types,
            'offers'              => $offers,
        ]];
    }
}
