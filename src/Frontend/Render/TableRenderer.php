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

                    $product_type = '';
                    if (!empty($brand['type'])) {
                        $product_type = $brand['type'];
                    } elseif (!empty($brand['productType'])) {
                        $product_type = $brand['productType'];
                    } elseif (!empty($brand['product_type'])) {
                        $product_type = $brand['product_type'];
                    }

                    $rating = '';
                    if (!empty($item['rating'])) {
                        $rating = (string) $item['rating'];
                    } elseif (!empty($brand['rating'])) {
                        $rating = (string) $brand['rating'];
                    }
                    ?>
                    <details style="border:1px solid #d1d5db;border-radius:8px;background:#fff;">
                        <summary style="cursor:pointer;padding:12px 14px;font-weight:600;background:#f9fafb;">
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
                                        <th style="text-align:left;border:1px solid #d1d5db;padding:8px;">Payout Time</th>
                                        <td style="border:1px solid #d1d5db;padding:8px;"><?php echo esc_html((string) (isset($offer['payout_time']) ? $offer['payout_time'] : '')); ?></td>
                                    </tr>
                                </tbody>
                            </table>

                            <table style="width:100%;border-collapse:collapse;font-size:14px;">
                                <tbody>
                                    <tr>
                                        <th style="text-align:left;border:1px solid #d1d5db;padding:8px;width:180px;">Max Payout</th>
                                        <td style="border:1px solid #d1d5db;padding:8px;"><?php echo esc_html((string) (isset($offer['max_payout']) ? $offer['max_payout'] : '')); ?></td>
                                    </tr>
                                    <tr>
                                        <th style="text-align:left;border:1px solid #d1d5db;padding:8px;">Games Count</th>
                                        <td style="border:1px solid #d1d5db;padding:8px;"><?php echo esc_html((string) (isset($item['games_count']) ? $item['games_count'] : '')); ?></td>
                                    </tr>
                                    <tr>
                                        <th style="text-align:left;border:1px solid #d1d5db;padding:8px;">Payment Methods</th>
                                        <td style="border:1px solid #d1d5db;padding:8px;"><?php echo esc_html(!empty($payment_methods) ? implode(', ', $payment_methods) : ''); ?></td>
                                    </tr>
                                    <tr>
                                        <th style="text-align:left;border:1px solid #d1d5db;padding:8px;">Licenses</th>
                                        <td style="border:1px solid #d1d5db;padding:8px;"><?php echo esc_html($licenses); ?></td>
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
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
