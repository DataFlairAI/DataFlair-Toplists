<?php
declare(strict_types=1);

namespace DataFlair\Toplists\Frontend\Render;

use DataFlair\Toplists\Frontend\Render\ViewModels\ToplistTableVM;

/**
 * Default accordion-table renderer.
 *
 * Phase 4 extraction of the god-class `render_toplist_table()` method used
 * by the block-editor's debug/testing layout (`layout=table`). The
 * production cards layout is rendered item-by-item through `CardRenderer`
 * directly from the shortcode; this class handles the alternative table
 * path only.
 *
 * The template is inlined for two reasons:
 *   1. It ships zero Tailwind classes and is documented as a testing UI.
 *   2. Keeping it in PHP avoids yet another include path consumers depend on.
 */
final class TableRenderer implements TableRendererInterface
{
    use ProsConsResolver;

    public function render(ToplistTableVM $vm): string
    {
        ob_start();

        $items = $vm->items;
        $title = $vm->title;
        $is_stale = $vm->isStale;
        $last_synced = $vm->lastSynced;
        $pros_cons_data = $vm->prosConsData;

        ?>
        <div class="dataflair-toplist dataflair-toplist-table">
            <?php if ($is_stale): ?>
                <div class="dataflair-notice">
                    ⚠️ This data was last updated on <?php echo date('M d, Y', $last_synced); ?>. Using cached version.
                </div>
            <?php endif; ?>

            <?php if (!empty($title)): ?>
                <h2 class="dataflair-title"><?php echo esc_html($title); ?></h2>
            <?php endif; ?>

            <div class="dataflair-toplist-accordion" style="display:flex;flex-direction:column;gap:12px;">
                <?php foreach ($items as $item): ?>
                    <?php
                    $brand = isset($item['brand']) && is_array($item['brand']) ? $item['brand'] : array();
                    $offer = isset($item['offer']) && is_array($item['offer']) ? $item['offer'] : array();
                    $resolved_pros_cons = $this->resolve_pros_cons_for_table_item($item, $pros_cons_data);

                    $payment_methods = array();
                    if (!empty($item['payment_methods']) && is_array($item['payment_methods'])) {
                        $payment_methods = $item['payment_methods'];
                    } elseif (!empty($item['paymentMethods']) && is_array($item['paymentMethods'])) {
                        $payment_methods = $item['paymentMethods'];
                    } elseif (!empty($brand['payment_methods']) && is_array($brand['payment_methods'])) {
                        $payment_methods = $brand['payment_methods'];
                    } elseif (!empty($brand['paymentMethods']) && is_array($brand['paymentMethods'])) {
                        $payment_methods = $brand['paymentMethods'];
                    }

                    $licenses = '';
                    if (!empty($brand['licenses'])) {
                        $licenses = is_array($brand['licenses']) ? implode(', ', $brand['licenses']) : (string) $brand['licenses'];
                    }

                    $features = '';
                    if (!empty($item['features']) && is_array($item['features'])) {
                        $features = implode(' | ', $item['features']);
                    }

                    // Product Type historically read brand.type/productType/product_type,
                    // none of which the API ever returns. classificationTypes is the
                    // real field that carries this (e.g. "Crypto Casino", "Sportsbook").
                    $product_type = '';
                    if (!empty($brand['classificationTypes']) && is_array($brand['classificationTypes'])) {
                        $product_type = implode(', ', $brand['classificationTypes']);
                    }

                    $rating = '';
                    if (!empty($item['rating'])) {
                        $rating = (string) $item['rating'];
                    } elseif (!empty($brand['rating'])) {
                        $rating = (string) $brand['rating'];
                    }

                    $restricted_countries = !empty($brand['restrictedCountries']) && is_array($brand['restrictedCountries'])
                        ? implode(', ', $brand['restrictedCountries']) : '';
                    $game_types = !empty($brand['gameTypes']) && is_array($brand['gameTypes'])
                        ? implode(', ', $brand['gameTypes']) : '';
                    $game_providers = !empty($brand['gameProviders']) && is_array($brand['gameProviders'])
                        ? implode(', ', $brand['gameProviders']) : '';
                    $languages = array();
                    if (!empty($brand['languages']) && is_array($brand['languages'])) {
                        foreach (array('website', 'support', 'livechat') as $lang_scope) {
                            if (!empty($brand['languages'][$lang_scope]) && is_array($brand['languages'][$lang_scope])) {
                                foreach ($brand['languages'][$lang_scope] as $lang) {
                                    if (!in_array($lang, $languages, true)) {
                                        $languages[] = $lang;
                                    }
                                }
                            }
                        }
                    }
                    $languages_display = !empty($languages) ? implode(', ', $languages) : '';

                    $currencies = !empty($offer['currencies']) && is_array($offer['currencies'])
                        ? implode(', ', $offer['currencies']) : '';

                    $free_spins_display = '';
                    if (!empty($offer['has_free_spins'])) {
                        $free_spins_display = !empty($offer['free_spin_count'])
                            ? 'Yes (' . (string) $offer['free_spin_count'] . ')'
                            : 'Yes';
                    }

                    $offer_geo_parts = array();
                    if (!empty($offer['geos']) && is_array($offer['geos'])) {
                        if (!empty($offer['geos']['countries']) && is_array($offer['geos']['countries'])) {
                            $offer_geo_parts[] = 'Countries: ' . implode(', ', $offer['geos']['countries']);
                        }
                        if (!empty($offer['geos']['markets']) && is_array($offer['geos']['markets'])) {
                            $offer_geo_parts[] = 'Markets: ' . implode(', ', $offer['geos']['markets']);
                        }
                    }
                    $offer_geo_display = implode(' | ', $offer_geo_parts);

                    // Any field only real for sportsbook/poker offers — render this
                    // whole section only when at least one is actually populated, so
                    // casino items (the majority) don't show a wall of empty rows.
                    $has_vertical_specific_fields = !empty($offer['minimum_odds'])
                        || !empty($offer['free_bet_value'])
                        || array_key_exists('stake_returned', $offer)
                        || !empty($offer['bet_type'])
                        || !empty($offer['tournament_ticket_value'])
                        || !empty($offer['rakeback_percentage'])
                        || !empty($offer['free_tickets']);

                    $trackers = !empty($offer['trackers']) && is_array($offer['trackers']) ? $offer['trackers'] : array();

                    $logo_url = '';
                    if (!empty($brand['logo']) && is_array($brand['logo'])) {
                        $logo_url = !empty($brand['logo']['square']) ? $brand['logo']['square']
                            : (!empty($brand['logo']['rectangular']) ? $brand['logo']['rectangular'] : '');
                    }
                    ?>
                    <details style="border:1px solid #d1d5db;border-radius:8px;background:#fff;">
                        <summary style="cursor:pointer;padding:12px 14px;font-weight:600;background:#f9fafb;display:flex;align-items:center;gap:8px;">
                            <?php if (!empty($logo_url)): ?>
                                <img src="<?php echo esc_html($logo_url); ?>" alt="" style="width:24px;height:24px;object-fit:contain;">
                            <?php endif; ?>
                            #<?php echo esc_html((string) (isset($item['position']) ? $item['position'] : '')); ?>
                            <?php echo esc_html((string) (isset($brand['name']) ? $brand['name'] : 'Unknown Brand')); ?>
                        </summary>
                        <div style="padding:12px;display:flex;flex-direction:column;gap:12px;">
                            <table style="width:100%;border-collapse:collapse;font-size:14px;">
                                <tbody>
                                    <tr>
                                        <th style="text-align:left;border:1px solid #d1d5db;padding:8px;width:180px;">Product Type</th>
                                        <td style="border:1px solid #d1d5db;padding:8px;"><?php echo esc_html((string) $product_type); ?></td>
                                    </tr>
                                    <tr>
                                        <th style="text-align:left;border:1px solid #d1d5db;padding:8px;">Rating</th>
                                        <td style="border:1px solid #d1d5db;padding:8px;"><?php echo esc_html($rating); ?></td>
                                    </tr>
                                    <tr>
                                        <th style="text-align:left;border:1px solid #d1d5db;padding:8px;">Offer Type</th>
                                        <td style="border:1px solid #d1d5db;padding:8px;"><?php echo esc_html((string) (isset($offer['offerTypeName']) ? $offer['offerTypeName'] : '')); ?></td>
                                    </tr>
                                    <tr>
                                        <th style="text-align:left;border:1px solid #d1d5db;padding:8px;">Offer</th>
                                        <td style="border:1px solid #d1d5db;padding:8px;"><?php echo esc_html((string) (isset($offer['offerText']) ? $offer['offerText'] : '')); ?></td>
                                    </tr>
                                    <tr>
                                        <th style="text-align:left;border:1px solid #d1d5db;padding:8px;">Bonus Code</th>
                                        <td style="border:1px solid #d1d5db;padding:8px;"><?php echo esc_html((string) (isset($offer['bonus_code']) ? $offer['bonus_code'] : '')); ?></td>
                                    </tr>
                                    <tr>
                                        <th style="text-align:left;border:1px solid #d1d5db;padding:8px;">Bonus Wagering</th>
                                        <td style="border:1px solid #d1d5db;padding:8px;"><?php echo esc_html((string) (isset($offer['bonus_wagering_requirement']) ? $offer['bonus_wagering_requirement'] : '')); ?></td>
                                    </tr>
                                    <tr>
                                        <th style="text-align:left;border:1px solid #d1d5db;padding:8px;">Min Deposit</th>
                                        <td style="border:1px solid #d1d5db;padding:8px;"><?php echo esc_html((string) (isset($offer['minimum_deposit']) ? $offer['minimum_deposit'] : '')); ?></td>
                                    </tr>
                                    <tr>
                                        <th style="text-align:left;border:1px solid #d1d5db;padding:8px;">Max Payout</th>
                                        <td style="border:1px solid #d1d5db;padding:8px;"><?php echo esc_html((string) (isset($offer['max_payout']) ? $offer['max_payout'] : '')); ?></td>
                                    </tr>
                                    <tr>
                                        <th style="text-align:left;border:1px solid #d1d5db;padding:8px;">Max Bonus Amount</th>
                                        <td style="border:1px solid #d1d5db;padding:8px;"><?php echo esc_html((string) (isset($offer['max_bonus_amount']) ? $offer['max_bonus_amount'] : '')); ?></td>
                                    </tr>
                                    <?php if ($free_spins_display !== ''): ?>
                                    <tr>
                                        <th style="text-align:left;border:1px solid #d1d5db;padding:8px;">Free Spins</th>
                                        <td style="border:1px solid #d1d5db;padding:8px;"><?php echo esc_html($free_spins_display); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                    <?php if (array_key_exists('is_sticky_bonus', $offer)): ?>
                                    <tr>
                                        <th style="text-align:left;border:1px solid #d1d5db;padding:8px;">Sticky Bonus</th>
                                        <td style="border:1px solid #d1d5db;padding:8px;"><?php echo esc_html(!empty($offer['is_sticky_bonus']) ? 'Yes' : 'No'); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                    <?php if (!empty($offer['bonus_expiry_date'])): ?>
                                    <tr>
                                        <th style="text-align:left;border:1px solid #d1d5db;padding:8px;">Bonus Expiry</th>
                                        <td style="border:1px solid #d1d5db;padding:8px;"><?php echo esc_html((string) $offer['bonus_expiry_date']); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                    <tr>
                                        <th style="text-align:left;border:1px solid #d1d5db;padding:8px;">Currencies</th>
                                        <td style="border:1px solid #d1d5db;padding:8px;"><?php echo esc_html($currencies); ?></td>
                                    </tr>
                                    <?php if ($offer_geo_display !== ''): ?>
                                    <tr>
                                        <th style="text-align:left;border:1px solid #d1d5db;padding:8px;">Offer Geo</th>
                                        <td style="border:1px solid #d1d5db;padding:8px;"><?php echo esc_html($offer_geo_display); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>

                            <table style="width:100%;border-collapse:collapse;font-size:14px;">
                                <tbody>
                                    <tr>
                                        <th style="text-align:left;border:1px solid #d1d5db;padding:8px;width:180px;">Payment Methods</th>
                                        <td style="border:1px solid #d1d5db;padding:8px;"><?php echo esc_html(!empty($payment_methods) ? implode(', ', $payment_methods) : ''); ?></td>
                                    </tr>
                                    <tr>
                                        <th style="text-align:left;border:1px solid #d1d5db;padding:8px;">Licenses</th>
                                        <td style="border:1px solid #d1d5db;padding:8px;"><?php echo esc_html($licenses); ?></td>
                                    </tr>
                                    <tr>
                                        <th style="text-align:left;border:1px solid #d1d5db;padding:8px;">Restricted Countries</th>
                                        <td style="border:1px solid #d1d5db;padding:8px;"><?php echo esc_html($restricted_countries); ?></td>
                                    </tr>
                                    <tr>
                                        <th style="text-align:left;border:1px solid #d1d5db;padding:8px;">Game Types</th>
                                        <td style="border:1px solid #d1d5db;padding:8px;"><?php echo esc_html($game_types); ?></td>
                                    </tr>
                                    <tr>
                                        <th style="text-align:left;border:1px solid #d1d5db;padding:8px;">Game Providers</th>
                                        <td style="border:1px solid #d1d5db;padding:8px;"><?php echo esc_html($game_providers); ?></td>
                                    </tr>
                                    <tr>
                                        <th style="text-align:left;border:1px solid #d1d5db;padding:8px;">Languages</th>
                                        <td style="border:1px solid #d1d5db;padding:8px;"><?php echo esc_html($languages_display); ?></td>
                                    </tr>
                                    <tr>
                                        <th style="text-align:left;border:1px solid #d1d5db;padding:8px;">Features</th>
                                        <td style="border:1px solid #d1d5db;padding:8px;"><?php echo esc_html($features); ?></td>
                                    </tr>
                                    <tr>
                                        <th style="text-align:left;border:1px solid #d1d5db;padding:8px;">Pros</th>
                                        <td style="border:1px solid #d1d5db;padding:8px;"><?php echo esc_html(!empty($resolved_pros_cons['pros']) ? implode(' | ', $resolved_pros_cons['pros']) : ''); ?></td>
                                    </tr>
                                    <tr>
                                        <th style="text-align:left;border:1px solid #d1d5db;padding:8px;">Cons</th>
                                        <td style="border:1px solid #d1d5db;padding:8px;"><?php echo esc_html(!empty($resolved_pros_cons['cons']) ? implode(' | ', $resolved_pros_cons['cons']) : ''); ?></td>
                                    </tr>
                                </tbody>
                            </table>

                            <?php if ($has_vertical_specific_fields): ?>
                            <table style="width:100%;border-collapse:collapse;font-size:14px;">
                                <tbody>
                                    <tr>
                                        <th style="text-align:left;border:1px solid #d1d5db;padding:8px;width:180px;">Minimum Odds</th>
                                        <td style="border:1px solid #d1d5db;padding:8px;"><?php echo esc_html((string) (isset($offer['minimum_odds']) ? $offer['minimum_odds'] : '')); ?></td>
                                    </tr>
                                    <tr>
                                        <th style="text-align:left;border:1px solid #d1d5db;padding:8px;">Free Bet Value</th>
                                        <td style="border:1px solid #d1d5db;padding:8px;"><?php echo esc_html((string) (isset($offer['free_bet_value']) ? $offer['free_bet_value'] : '')); ?></td>
                                    </tr>
                                    <tr>
                                        <th style="text-align:left;border:1px solid #d1d5db;padding:8px;">Stake Returned</th>
                                        <td style="border:1px solid #d1d5db;padding:8px;"><?php echo esc_html(!empty($offer['stake_returned']) ? 'Yes' : 'No'); ?></td>
                                    </tr>
                                    <tr>
                                        <th style="text-align:left;border:1px solid #d1d5db;padding:8px;">Bet Type</th>
                                        <td style="border:1px solid #d1d5db;padding:8px;"><?php echo esc_html((string) (isset($offer['bet_type']) ? $offer['bet_type'] : '')); ?></td>
                                    </tr>
                                    <tr>
                                        <th style="text-align:left;border:1px solid #d1d5db;padding:8px;">Tournament Ticket Value</th>
                                        <td style="border:1px solid #d1d5db;padding:8px;"><?php echo esc_html((string) (isset($offer['tournament_ticket_value']) ? $offer['tournament_ticket_value'] : '')); ?></td>
                                    </tr>
                                    <tr>
                                        <th style="text-align:left;border:1px solid #d1d5db;padding:8px;">Rakeback %</th>
                                        <td style="border:1px solid #d1d5db;padding:8px;"><?php echo esc_html((string) (isset($offer['rakeback_percentage']) ? $offer['rakeback_percentage'] : '')); ?></td>
                                    </tr>
                                    <tr>
                                        <th style="text-align:left;border:1px solid #d1d5db;padding:8px;">Free Tickets</th>
                                        <td style="border:1px solid #d1d5db;padding:8px;"><?php echo esc_html((string) (isset($offer['free_tickets']) ? $offer['free_tickets'] : '')); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                            <?php endif; ?>

                            <?php if (!empty($trackers)): ?>
                            <table style="width:100%;border-collapse:collapse;font-size:14px;">
                                <thead>
                                    <tr>
                                        <th style="text-align:left;border:1px solid #d1d5db;padding:8px;background:#f9fafb;">Campaign</th>
                                        <th style="text-align:left;border:1px solid #d1d5db;padding:8px;background:#f9fafb;">Tracker Link</th>
                                        <th style="text-align:left;border:1px solid #d1d5db;padding:8px;background:#f9fafb;">T&amp;C Link</th>
                                        <th style="text-align:left;border:1px solid #d1d5db;padding:8px;background:#f9fafb;">Page Type</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($trackers as $tracker): ?>
                                    <tr>
                                        <td style="border:1px solid #d1d5db;padding:8px;"><?php echo esc_html((string) (isset($tracker['campaignName']) ? $tracker['campaignName'] : '')); ?></td>
                                        <td style="border:1px solid #d1d5db;padding:8px;word-break:break-all;"><?php echo esc_html((string) (isset($tracker['trackerLink']) ? $tracker['trackerLink'] : '')); ?></td>
                                        <td style="border:1px solid #d1d5db;padding:8px;word-break:break-all;"><?php echo esc_html((string) (isset($tracker['tcLink']) ? $tracker['tcLink'] : '')); ?></td>
                                        <td style="border:1px solid #d1d5db;padding:8px;"><?php echo esc_html((string) (isset($tracker['pageType']) ? $tracker['pageType'] : '')); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php endif; ?>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
