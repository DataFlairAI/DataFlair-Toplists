<?php
/**
 * Phase 9.6 — DataFlair brands admin screen.
 *
 * Owns the rendering of `Toplists → Brands`. Extracted verbatim from
 * `DataFlair_Toplists::brands_page()` so HTML output is byte-identical to
 * v2.1.1 — downstream admin CSS targeting these IDs/classes keeps working.
 *
 * Two private god-class helpers are still needed:
 *   - `format_last_sync_label(string $option): string`
 *   - `collect_distinct_csv_values(string $table, string $column): array`
 * They're injected as closures (`\Closure::fromCallable([$this, …])` from
 * the bootstrap) so this class never touches the legacy class.
 *
 * Single responsibility: render the brands list page. Pagination,
 * client-side filters/sort, and the inline review-URL editor all live in
 * the template body. Extracting them into BrandsTable / BrandsTableFilters
 * is a follow-up for a later release; for v2.1.2 we only move the body
 * into its own class to drop ~1,237 LOC out of the god class.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Pages;

final class BrandsPage implements PageInterface
{
    public function __construct(
        private \Closure $distinctCsvValuesCollector,
        private \Closure $lastSyncLabelFormatter
    ) {}

    public function render(): void
    {
        global $wpdb;
        $brands_table_name = $wpdb->prefix . DATAFLAIR_BRANDS_TABLE_NAME;

        // Phase 0B H5: server-side pagination caps the number of rows (and
        // data-blob JSON) pulled into PHP at any one time. Previously the page
        // loaded every brand (500+) with its full `data` column into memory —
        // ~40MB+ of blobs, and an obvious latent-OOM hotspot on admin pages.
        $per_page   = 50;
        $paged      = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset     = ($paged - 1) * $per_page;
        $total_brands = (int) $wpdb->get_var("SELECT COUNT(*) FROM $brands_table_name");
        $total_pages  = max(1, (int) ceil($total_brands / $per_page));

        // Lean projection: everything the list row needs, minus the heavy `data` blob.
        $brands = $wpdb->get_results($wpdb->prepare(
            "SELECT id, api_brand_id, name, slug, product_types, licenses, top_geos,
                    offers_count, trackers_count, last_synced, review_url_override
             FROM $brands_table_name
             ORDER BY name ASC
             LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));

        // Batched fetch of data blobs for the current page only (one extra SELECT).
        $data_by_api_brand_id = array();
        if (!empty($brands)) {
            $api_brand_ids = array();
            foreach ($brands as $b) {
                if (!empty($b->api_brand_id)) $api_brand_ids[] = intval($b->api_brand_id);
            }
            if (!empty($api_brand_ids)) {
                $placeholders = implode(',', array_fill(0, count($api_brand_ids), '%d'));
                $data_rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT api_brand_id, data FROM $brands_table_name WHERE api_brand_id IN ($placeholders)",
                    $api_brand_ids
                ));
                foreach ((array) $data_rows as $row) {
                    $data_by_api_brand_id[intval($row->api_brand_id)] = $row->data;
                }
            }
            foreach ($brands as $b) {
                $b->data = $data_by_api_brand_id[intval($b->api_brand_id)] ?? '{}';
            }
        }

        ?>
        <div class="wrap">
            <h1>DataFlair Brands</h1>

            <?php settings_errors(); ?>

            <p>Manage brands from the DataFlair API. Only active brands are synced.</p>

            <h2>Sync Brands</h2>
            <button type="button" id="dataflair-fetch-all-brands" class="button button-primary">
                Sync Brands from API
            </button>
            <span id="dataflair-fetch-brands-message" style="margin-left: 10px;"></span>

            <!-- Progress Bar -->
            <div id="dataflair-sync-progress" style="display: none; margin-top: 15px;">
                <div style="background: #f0f0f1; border-radius: 4px; height: 24px; position: relative; overflow: hidden; border: 1px solid #dcdcde;">
                    <div id="dataflair-progress-bar" style="background: linear-gradient(90deg, #2271b1 0%, #135e96 100%); height: 100%; width: 0%; transition: width 0.3s ease; display: flex; align-items: center; justify-content: center;">
                        <span id="dataflair-progress-text" style="color: white; font-size: 12px; font-weight: 600; position: absolute; left: 50%; transform: translateX(-50%);"></span>
                    </div>
                </div>
            </div>

            <p class="description">Fetches all active brands from the DataFlair API in batches of 25. Existing brands will be updated.</p>
            <p class="description">Sync runs only when triggered here or via WP-CLI. <?php echo esc_html(($this->lastSyncLabelFormatter)('dataflair_last_brands_sync')); ?></p>

            <hr>

            <h2>Synced Brands (showing <?php echo count($brands); ?> of <?php echo esc_html($total_brands); ?>)</h2>
            <?php if ($brands): ?>
                <?php
                // Phase 0B H5: distinct-value queries against lean CSV columns
                // (licenses, top_geos) instead of parsing every brand's `data`
                // JSON blob in PHP. Payment-method filter still populates from
                // the current page's data blobs only — sufficient for admin UX,
                // and keeps paymentMethods out of the aggregate query path.
                $all_licenses        = ($this->distinctCsvValuesCollector)($brands_table_name, 'licenses');
                $all_geos            = ($this->distinctCsvValuesCollector)($brands_table_name, 'top_geos');
                $all_payment_methods = array();

                foreach ($brands as $brand) {
                    $data = json_decode($brand->data, true);
                    if (!empty($data['paymentMethods']) && is_array($data['paymentMethods'])) {
                        $all_payment_methods = array_merge($all_payment_methods, $data['paymentMethods']);
                    }
                }
                $all_payment_methods = array_unique($all_payment_methods);
                sort($all_payment_methods);
                ?>

                <!-- Filters -->
                <div class="dataflair-filters">
                    <div class="filter-row">
                        <!-- License Filter -->
                        <div class="filter-group">
                            <label>Licenses</label>
                            <select id="dataflair-filter-licenses" class="dataflair-select2" multiple="multiple" data-filter-type="licenses" style="width: 100%;">
                                <?php foreach ($all_licenses as $license): ?>
                                    <option value="<?php echo esc_attr($license); ?>"><?php echo esc_html($license); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Geo Filter -->
                        <div class="filter-group">
                            <label>Top Geos</label>
                            <select id="dataflair-filter-top-geos" class="dataflair-select2" multiple="multiple" data-filter-type="top_geos" style="width: 100%;">
                                <?php foreach ($all_geos as $geo): ?>
                                    <option value="<?php echo esc_attr($geo); ?>"><?php echo esc_html($geo); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Payment Filter -->
                        <div class="filter-group">
                            <label>Payment Methods</label>
                            <select id="dataflair-filter-payment-methods" class="dataflair-select2" multiple="multiple" data-filter-type="payment_methods" style="width: 100%;">
                                <?php foreach ($all_payment_methods as $method): ?>
                                    <option value="<?php echo esc_attr($method); ?>"><?php echo esc_html($method); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Actions -->
                        <div class="filter-group filter-actions">
                            <button type="button" id="dataflair-clear-all-filters" class="button">Clear All Filters</button>
                            <span id="dataflair-brands-count">Showing <?php echo count($brands); ?> brands</span>
                        </div>
                    </div>
                </div>

                <table class="wp-list-table widefat striped dataflair-brands-table">
                    <thead>
                        <tr>
                            <th style="width: 3%;"></th>
                            <th style="width: 5%;">ID</th>
                            <th style="width: 8%;">Logo</th>
                            <th style="width: 14%;" class="sortable">
                                <a href="#" class="sort-link" data-sort="name">
                                    Brand Name
                                    <span class="sorting-indicator">
                                        <span class="dashicons dashicons-sort"></span>
                                    </span>
                                </a>
                            </th>
                            <th style="width: 10%;">Product Type</th>
                            <th style="width: 12%;">Licenses</th>
                            <th style="width: 16%;">Top Geos</th>
                            <th style="width: 7%;" class="sortable">
                                <a href="#" class="sort-link" data-sort="offers">
                                    Offers
                                    <span class="sorting-indicator">
                                        <span class="dashicons dashicons-sort"></span>
                                    </span>
                                </a>
                            </th>
                            <th style="width: 7%;" class="sortable">
                                <a href="#" class="sort-link" data-sort="trackers">
                                    Trackers
                                    <span class="sorting-indicator">
                                        <span class="dashicons dashicons-sort"></span>
                                    </span>
                                </a>
                            </th>
                            <th style="width: 13%;">Last Synced</th>
                            <th style="width: 15%;">Review URL</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($brands as $index => $brand):
                            $data = json_decode($brand->data, true);
                            $brand_id = 'brand-' . $brand->api_brand_id;

                            // Prepare filter data attributes
                            $licenses_json = !empty($data['licenses']) ? json_encode($data['licenses']) : '[]';

                            $geos = array();
                            if (!empty($data['topGeos']['countries'])) {
                                $geos = array_merge($geos, $data['topGeos']['countries']);
                            }
                            if (!empty($data['topGeos']['markets'])) {
                                $geos = array_merge($geos, $data['topGeos']['markets']);
                            }
                            $geos_json = json_encode($geos);

                            $payments_json = !empty($data['paymentMethods']) ? json_encode($data['paymentMethods']) : '[]';
                        ?>
                        <tr class="brand-row"
                            data-brand-name="<?php echo esc_attr($brand->name); ?>"
                            data-offers-count="<?php echo esc_attr($brand->offers_count); ?>"
                            data-trackers-count="<?php echo esc_attr($brand->trackers_count); ?>"
                            data-brand-data='<?php echo esc_attr(json_encode(array(
                                'licenses' => !empty($data['licenses']) ? $data['licenses'] : array(),
                                'topGeos' => $geos,
                                'paymentMethods' => !empty($data['paymentMethods']) ? $data['paymentMethods'] : array()
                            ))); ?>'>
                            <td class="toggle-cell">
                                <button type="button" class="brand-toggle" aria-expanded="false">
                                    <span class="dashicons dashicons-arrow-right-alt2"></span>
                                </button>
                            </td>
                            <td><?php echo esc_html($brand->api_brand_id); ?></td>
                            <td class="brand-logo-cell">
                                <?php
                                // Get logo URL - prioritize local_logo
                                $logo_url = '';
                                if (!empty($data['local_logo'])) {
                                    $logo_url = $data['local_logo'];
                                } else {
                                    // Fallback to external logo with nested structure support
                                    $logo_keys = array('logo', 'brandLogo', 'logoUrl', 'image');
                                    foreach ($logo_keys as $key) {
                                        if (!empty($data[$key])) {
                                            if (is_array($data[$key])) {
                                                // Check for nested logo object with rectangular/square
                                                $logo_url = $data[$key]['rectangular'] ??
                                                           $data[$key]['square'] ??
                                                           $data[$key]['url'] ??
                                                           $data[$key]['src'] ?? '';
                                            } else {
                                                $logo_url = $data[$key];
                                            }
                                            if ($logo_url) break;
                                        }
                                    }
                                }

                                if ($logo_url && !is_array($logo_url)): ?>
                                    <img src="<?php echo esc_url($logo_url); ?>"
                                         alt="<?php echo esc_attr($brand->name); ?>"
                                         class="brand-logo-thumb"
                                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                    <div class="brand-logo-placeholder" style="display: none;">
                                        <?php echo esc_html(strtoupper(substr($brand->name, 0, 2))); ?>
                                    </div>
                                <?php else: ?>
                                    <div class="brand-logo-placeholder">
                                        <?php echo esc_html(strtoupper(substr($brand->name, 0, 2))); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?php echo esc_html($brand->name); ?></strong>
                                <div class="row-actions">
                                    <span class="slug"><?php echo esc_html($brand->slug); ?></span>
                                </div>
                            </td>
                            <td><?php echo esc_html($brand->product_types ?: '—'); ?></td>
                            <td>
                                <?php
                                $licenses = $brand->licenses;
                                if (strlen($licenses) > 25) {
                                    echo '<span title="' . esc_attr($licenses) . '">' . esc_html(substr($licenses, 0, 25)) . '...</span>';
                                } else {
                                    echo esc_html($licenses ?: '—');
                                }
                                ?>
                            </td>
                            <td>
                                <?php
                                $top_geos = $brand->top_geos;
                                if (strlen($top_geos) > 35) {
                                    echo '<span title="' . esc_attr($top_geos) . '">' . esc_html(substr($top_geos, 0, 35)) . '...</span>';
                                } else {
                                    echo esc_html($top_geos ?: '—');
                                }
                                ?>
                            </td>
                            <td style="text-align: center;">
                                <?php if ($brand->offers_count > 0): ?>
                                    <span class="count"><?php echo esc_html($brand->offers_count); ?></span>
                                <?php else: ?>
                                    <span style="color: #999;">0</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center;">
                                <?php if ($brand->trackers_count > 0): ?>
                                    <span class="count"><?php echo esc_html($brand->trackers_count); ?></span>
                                <?php else: ?>
                                    <span style="color: #999;">0</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html(date('Y-m-d H:i', strtotime($brand->last_synced))); ?></td>
                            <?php
                                $has_override = !empty($brand->review_url_override);
                            ?>
                            <td>
                                <input type="text"
                                       class="dataflair-review-url-input"
                                       data-brand-id="<?php echo esc_attr($brand->api_brand_id); ?>"
                                       value="<?php echo esc_attr($brand->review_url_override ?? ''); ?>"
                                       placeholder="/reviews/brand-slug/"
                                       style="width:220px;<?php echo $has_override ? ' background:#f0f0f0; color:#777;' : ''; ?>"
                                       <?php echo $has_override ? 'disabled' : ''; ?> />
                                <button class="button dataflair-save-review-url" data-brand-id="<?php echo esc_attr($brand->api_brand_id); ?>" data-mode="<?php echo $has_override ? 'edit' : 'save'; ?>">
                                    <?php echo $has_override ? 'Edit' : 'Save'; ?>
                                </button>
                            </td>
                        </tr>

                        <!-- Expandable Details Row -->
                        <tr class="brand-details" id="<?php echo esc_attr($brand_id); ?>" style="display: none;">
                            <td colspan="10">
                                <div class="brand-details-content">
                                    <div class="details-grid">
                                        <div class="detail-section">
                                            <h4>Payment Methods</h4>
                                            <div class="detail-content">
                                                <?php
                                                if (!empty($data['paymentMethods']) && is_array($data['paymentMethods'])) {
                                                    echo '<div class="badge-list">';
                                                    foreach (array_slice($data['paymentMethods'], 0, 15) as $method) {
                                                        echo '<span class="badge">' . esc_html($method) . '</span>';
                                                    }
                                                    if (count($data['paymentMethods']) > 15) {
                                                        $remaining = array_slice($data['paymentMethods'], 15);
                                                        $tooltip = esc_attr(implode(', ', $remaining));
                                                        echo '<span class="badge more with-tooltip" title="' . $tooltip . '">+' . (count($data['paymentMethods']) - 15) . ' more</span>';
                                                    }
                                                    echo '</div>';
                                                } else {
                                                    echo '<span class="no-data">No payment methods</span>';
                                                }
                                                ?>
                                            </div>
                                        </div>

                                        <div class="detail-section">
                                            <h4>Currencies</h4>
                                            <div class="detail-content">
                                                <?php
                                                if (!empty($data['currencies']) && is_array($data['currencies'])) {
                                                    echo '<div class="badge-list">';
                                                    foreach ($data['currencies'] as $currency) {
                                                        echo '<span class="badge">' . esc_html($currency) . '</span>';
                                                    }
                                                    echo '</div>';
                                                } else {
                                                    echo '<span class="no-data">No currencies specified</span>';
                                                }
                                                ?>
                                            </div>
                                        </div>

                                        <div class="detail-section">
                                            <h4>Rating</h4>
                                            <div class="detail-content">
                                                <?php
                                                $rating = isset($data['rating']) ? $data['rating'] : null;
                                                if ($rating) {
                                                    echo '<span class="rating">' . esc_html($rating) . ' / 5</span>';
                                                } else {
                                                    echo '<span class="no-data">Not rated</span>';
                                                }
                                                ?>
                                            </div>
                                        </div>

                                        <div class="detail-section full-width">
                                            <h4>Game Providers</h4>
                                            <div class="detail-content">
                                                <?php
                                                if (!empty($data['gameProviders']) && is_array($data['gameProviders'])) {
                                                    echo '<div class="badge-list">';
                                                    foreach ($data['gameProviders'] as $provider) {
                                                        echo '<span class="badge">' . esc_html($provider) . '</span>';
                                                    }
                                                    echo '</div>';
                                                } else {
                                                    echo '<span class="no-data">No game providers specified</span>';
                                                }
                                                ?>
                                            </div>
                                        </div>

                                        <div class="detail-section full-width">
                                            <h4>Restricted Countries</h4>
                                            <div class="detail-content restricted-countries">
                                                <?php
                                                if (!empty($data['restrictedCountries']) && is_array($data['restrictedCountries'])) {
                                                    echo '<div class="badge-list">';
                                                    foreach (array_slice($data['restrictedCountries'], 0, 20) as $country) {
                                                        echo '<span class="badge badge-gray">' . esc_html($country) . '</span>';
                                                    }
                                                    if (count($data['restrictedCountries']) > 20) {
                                                        $remaining = array_slice($data['restrictedCountries'], 20);
                                                        $tooltip = esc_attr(implode(', ', $remaining));
                                                        echo '<span class="badge badge-gray more with-tooltip" title="' . $tooltip . '">+' . (count($data['restrictedCountries']) - 20) . ' more</span>';
                                                    }
                                                    echo '</div>';
                                                } else {
                                                    echo '<span class="no-data">No restrictions</span>';
                                                }
                                                ?>
                                            </div>
                                        </div>

                                        <div class="detail-section">
                                            <h4>Customer Support</h4>
                                            <div class="detail-content">
                                                <?php
                                                $hasCustomerSupport = !empty($data['languages']['customerSupport']) && is_array($data['languages']['customerSupport']) && count($data['languages']['customerSupport']) > 0;
                                                if ($hasCustomerSupport) {
                                                    echo '<span class="support-available">Available</span><br>';
                                                    echo '<div class="badge-list" style="margin-top: 6px;">';
                                                    foreach ($data['languages']['customerSupport'] as $lang) {
                                                        echo '<span class="badge">' . esc_html($lang) . '</span>';
                                                    }
                                                    echo '</div>';
                                                } else {
                                                    echo '<span class="no-data">Not specified</span>';
                                                }
                                                ?>
                                            </div>
                                        </div>

                                        <div class="detail-section">
                                            <h4>Live Chat</h4>
                                            <div class="detail-content">
                                                <?php
                                                $hasLiveChat = !empty($data['languages']['livechat']) && is_array($data['languages']['livechat']) && count($data['languages']['livechat']) > 0;
                                                if ($hasLiveChat) {
                                                    echo '<span class="support-available">Available</span><br>';
                                                    echo '<div class="badge-list" style="margin-top: 6px;">';
                                                    foreach ($data['languages']['livechat'] as $lang) {
                                                        echo '<span class="badge">' . esc_html($lang) . '</span>';
                                                    }
                                                    echo '</div>';
                                                } else {
                                                    echo '<span class="no-data">Not available</span>';
                                                }
                                                ?>
                                            </div>
                                        </div>

                                        <div class="detail-section full-width">
                                            <h4>Website Languages</h4>
                                            <div class="detail-content">
                                                <?php
                                                if (!empty($data['languages']['website']) && is_array($data['languages']['website'])) {
                                                    echo '<div class="badge-list">';
                                                    foreach ($data['languages']['website'] as $lang) {
                                                        echo '<span class="badge">' . esc_html($lang) . '</span>';
                                                    }
                                                    echo '</div>';
                                                } else {
                                                    echo '<span class="no-data">Not specified</span>';
                                                }
                                                ?>
                                            </div>
                                        </div>
                                    </div>

                                    <hr class="details-separator">

                                    <div class="offers-section">
                                        <h4>Offers (<?php echo $brand->offers_count; ?>)</h4>
                                        <?php if (!empty($data['offers']) && is_array($data['offers'])): ?>
                                            <div class="offers-list">
                                                <?php foreach ($data['offers'] as $offer): ?>
                                                    <div class="offer-item-inline">
                                                        <span class="offer-type-badge"><?php echo esc_html($offer['offerTypeName'] ?? 'Unknown'); ?></span>
                                                        <span class="offer-separator">:</span>
                                                        <span class="offer-text-inline"><?php echo esc_html($offer['offerText'] ?? 'No description'); ?></span>
                                                        <span class="offer-separator">:</span>
                                                        <span class="offer-geo-inline">
                                                            <?php
                                                            $geos = array();
                                                            if (!empty($offer['geos']['countries'])) {
                                                                $geos = array_merge($geos, $offer['geos']['countries']);
                                                            }
                                                            if (!empty($offer['geos']['markets'])) {
                                                                $geos = array_merge($geos, $offer['geos']['markets']);
                                                            }
                                                            echo $geos ? esc_html(implode(', ', $geos)) : 'All Geos';
                                                            ?>
                                                        </span>
                                                        <span class="offer-separator">:</span>
                                                        <span class="offer-tracker-inline">
                                                            <?php
                                                            $trackers = isset($offer['trackers']) && is_array($offer['trackers']) ? $offer['trackers'] : array();
                                                            if (count($trackers) > 0) {
                                                                echo '<span class="tracker-count-inline">' . count($trackers) . ' tracker' . (count($trackers) > 1 ? 's' : '') . '</span>';
                                                            } else {
                                                                echo '<span class="no-trackers-inline">No trackers</span>';
                                                            }
                                                            ?>
                                                        </span>
                                                        <span class="offer-separator">|</span>
                                                        <span class="offer-extra-info">
                                                            <?php
                                                            $extras = array();
                                                            if (!empty($offer['bonus_wagering_requirement'])) {
                                                                $extras[] = 'Wagering: ' . esc_html($offer['bonus_wagering_requirement']) . 'x';
                                                            }
                                                            if (!empty($offer['minimum_deposit'])) {
                                                                $extras[] = 'Min Deposit: $' . esc_html($offer['minimum_deposit']);
                                                            }
                                                            if (!empty($offer['bonus_code'])) {
                                                                $extras[] = 'Code: ' . esc_html($offer['bonus_code']);
                                                            }
                                                            if (!empty($offer['has_free_spins'])) {
                                                                $extras[] = '<span class="badge-free-spins">Free Spins</span>';
                                                            }
                                                            echo implode(' | ', $extras);
                                                            ?>
                                                        </span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <p class="no-data">No offers available</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Phase 0B H5: server-side pagination. Client-side filter
                     pagination below still operates within the server page. -->
                <?php
                $page_url = add_query_arg(null, null);
                $first_url = remove_query_arg('paged', $page_url);
                $prev_url  = $paged > 1 ? add_query_arg('paged', $paged - 1, $page_url) : '#';
                $next_url  = $paged < $total_pages ? add_query_arg('paged', $paged + 1, $page_url) : '#';
                $last_url  = $total_pages > 1 ? add_query_arg('paged', $total_pages, $page_url) : '#';
                ?>
                <div class="tablenav top" style="margin-top: 12px;">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo esc_html($total_brands); ?> brands total &mdash; page <?php echo esc_html($paged); ?> of <?php echo esc_html($total_pages); ?></span>
                        <span class="pagination-links" style="margin-left: 10px;">
                            <a class="first-page button <?php echo $paged <= 1 ? 'disabled' : ''; ?>" href="<?php echo esc_url($paged > 1 ? $first_url : '#'); ?>"><span aria-hidden="true">«</span></a>
                            <a class="prev-page button <?php echo $paged <= 1 ? 'disabled' : ''; ?>" href="<?php echo esc_url($prev_url); ?>"><span aria-hidden="true">‹</span></a>
                            <span class="paging-input"> Page <?php echo esc_html($paged); ?> of <?php echo esc_html($total_pages); ?> </span>
                            <a class="next-page button <?php echo $paged >= $total_pages ? 'disabled' : ''; ?>" href="<?php echo esc_url($next_url); ?>"><span aria-hidden="true">›</span></a>
                            <a class="last-page button <?php echo $paged >= $total_pages ? 'disabled' : ''; ?>" href="<?php echo esc_url($last_url); ?>"><span aria-hidden="true">»</span></a>
                        </span>
                    </div>
                </div>

                <!-- Pagination (client-side filter pagination within server page) -->
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="displaying-num" id="brands-total-count"><?php echo count($brands); ?> items on page</span>
                        <span class="pagination-links" style="margin-right: 10px;">
                            <label for="items-per-page-selector" style="margin-right: 5px;">Filter view:</label>
                            <select id="items-per-page-selector" style="margin-right: 10px;">
                                <option value="10">10</option>
                                <option value="20" selected>20</option>
                                <option value="50">50</option>
                            </select>
                        </span>
                        <span class="pagination-links">
                            <a class="first-page button" href="#" id="pagination-first" disabled>
                                <span class="screen-reader-text">First page</span>
                                <span aria-hidden="true">«</span>
                            </a>
                            <a class="prev-page button" href="#" id="pagination-prev" disabled>
                                <span class="screen-reader-text">Previous page</span>
                                <span aria-hidden="true">‹</span>
                            </a>
                            <span class="paging-input">
                                <label for="current-page-selector" class="screen-reader-text">Current Page</label>
                                <input class="current-page" id="current-page-selector" type="text" name="paged" value="1" size="2" aria-describedby="table-paging">
                                <span class="tablenav-paging-text"> of <span class="total-pages" id="total-pages">1</span></span>
                            </span>
                            <a class="next-page button" href="#" id="pagination-next">
                                <span class="screen-reader-text">Next page</span>
                                <span aria-hidden="true">›</span>
                            </a>
                            <a class="last-page button" href="#" id="pagination-last">
                                <span class="screen-reader-text">Last page</span>
                                <span aria-hidden="true">»</span>
                            </a>
                        </span>
                    </div>
                </div>

                <script>
                jQuery(document).on('click', '.dataflair-save-review-url', function() {
                    var btn   = jQuery(this);
                    var brandId = btn.data('brand-id');
                    var input = jQuery('.dataflair-review-url-input[data-brand-id="' + brandId + '"]');
                    var mode  = btn.data('mode') || 'save';

                    // Edit mode: unlock the field for editing
                    if (mode === 'edit') {
                        input.prop('disabled', false).css({ background: '', color: '' }).focus();
                        btn.text('Save').data('mode', 'save');
                        return;
                    }

                    // Save mode: persist and lock
                    var url = input.val();
                    btn.text('Saving...').prop('disabled', true);
                    jQuery.post(ajaxurl, {
                        action: 'dataflair_save_review_url',
                        brand_id: brandId,
                        review_url: url,
                        nonce: '<?php echo wp_create_nonce("dataflair_save_review_url"); ?>'
                    }, function(response) {
                        if (response.success) {
                            btn.text('Saved ✓').prop('disabled', false);
                            setTimeout(function() {
                                input.prop('disabled', true).css({ background: '#f0f0f0', color: '#777' });
                                btn.text('Edit').data('mode', 'edit');
                            }, 1000);
                        } else {
                            btn.text('Error').prop('disabled', false);
                            setTimeout(function() { btn.text('Save'); }, 2000);
                        }
                    });
                });
                </script>

                <style>
                    /* Select2 styling for WordPress admin */
                    .select2-container {
                        z-index: 999999;
                    }
                    .select2-container--default .select2-selection--multiple {
                        border: 1px solid #8c8f94;
                        border-radius: 4px;
                        min-height: 30px;
                        padding: 2px;
                    }
                    .select2-container--default .select2-selection--multiple .select2-selection__choice {
                        background-color: #2271b1;
                        border: 1px solid #2271b1;
                        color: #fff;
                        padding: 2px 6px;
                        margin: 2px;
                        border-radius: 3px;
                        display: inline-flex;
                        align-items: center;
                    }
                    .select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
                        /* Select2 v4.1+ renders this as a <button> — reset browser defaults */
                        border: none;
                        background: rgba(255, 255, 255, 0.25);
                        padding: 0;
                        margin-right: 0;
                        color: #fff;
                        cursor: pointer;
                        font-size: 13px;
                        font-weight: 700;
                        line-height: 1;
                        display: inline-flex;
                        align-items: center;
                        justify-content: center;
                        width: 16px;
                        height: 16px;
                        border-radius: 50%;
                        flex-shrink: 0;
                    }
                    .select2-container--default .select2-selection--multiple .select2-selection__choice__remove:hover {
                        background: rgba(255, 255, 255, 0.45);
                        color: #fff;
                    }
                    /* Inner ×  span — strip any inherited spacing so button stays square */
                    .select2-container--default .select2-selection--multiple .select2-selection__choice__remove span {
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        line-height: 1;
                        margin: 0;
                        padding: 0;
                    }
                    /* Display label — own the gap from the × button */
                    .select2-container--default .select2-selection--multiple .select2-selection__choice__display {
                        padding-left: 15px;
                    }
                    .select2-container--default .select2-search--inline .select2-search__field {
                        margin-top: 2px;
                        padding: 2px;
                    }
                    .filter-group .select2-container {
                        margin-top: 5px;
                    }

                    /* Filters */
                    .dataflair-filters {
                        background: #f9f9f9;
                        border: 1px solid #dcdcde;
                        border-radius: 6px;
                        padding: 20px;
                        margin-bottom: 20px;
                        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
                    }
                    .filter-row {
                        display: flex;
                        align-items: flex-end;
                        gap: 16px;
                        flex-wrap: wrap;
                    }
                    .filter-group {
                        display: flex;
                        flex-direction: column;
                        gap: 8px;
                        flex: 1;
                        min-width: 220px;
                        max-width: 280px;
                    }
                    .filter-group > label {
                        font-weight: 600;
                        font-size: 13px;
                        color: #1d2327;
                        letter-spacing: 0;
                    }

                    /* Custom Multiselect */
                    .custom-multiselect {
                        position: relative;
                        flex: 1;
                    }
                    .multiselect-toggle {
                        width: 100%;
                        padding: 8px 12px;
                        background: white;
                        border: 1px solid #8c8f94;
                        border-radius: 4px;
                        font-size: 13px;
                        text-align: left;
                        cursor: pointer;
                        display: flex;
                        justify-content: space-between;
                        align-items: center;
                        transition: all 0.2s;
                        min-height: 38px;
                        box-shadow: 0 1px 2px rgba(0,0,0,0.05);
                    }
                    .multiselect-toggle:hover {
                        border-color: #2271b1;
                        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                    }
                    .multiselect-toggle .dashicons {
                        font-size: 18px;
                        width: 18px;
                        height: 18px;
                        transition: transform 0.2s;
                        color: #8c8f94;
                        flex-shrink: 0;
                        margin-left: 8px;
                    }
                    .custom-multiselect.open .multiselect-toggle .dashicons {
                        transform: rotate(180deg);
                        color: #2271b1;
                    }
                    .custom-multiselect.open .multiselect-toggle {
                        border-color: #2271b1;
                        box-shadow: 0 0 0 1px #2271b1;
                    }
                    .selected-text {
                        overflow: hidden;
                        text-overflow: ellipsis;
                        white-space: nowrap;
                        flex: 1;
                    }

                    /* Dropdown */
                    .multiselect-dropdown {
                        position: absolute;
                        top: 100%;
                        left: 0;
                        right: 0;
                        background: white;
                        border: 1px solid #2271b1;
                        border-radius: 3px;
                        margin-top: 4px;
                        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                        z-index: 1000;
                        display: none;
                    }
                    .custom-multiselect.open .multiselect-dropdown {
                        display: block;
                    }

                    /* Search */
                    .multiselect-search {
                        padding: 8px;
                        border-bottom: 1px solid #dcdcde;
                    }
                    .multiselect-search input {
                        width: 100%;
                        padding: 4px 8px;
                        border: 1px solid #8c8f94;
                        border-radius: 3px;
                        font-size: 12px;
                    }
                    .multiselect-search input:focus {
                        border-color: #2271b1;
                        outline: none;
                    }

                    /* Actions */
                    .multiselect-actions {
                        padding: 6px 8px;
                        border-bottom: 1px solid #dcdcde;
                        display: flex;
                        gap: 12px;
                        font-size: 11px;
                    }
                    .multiselect-actions a {
                        color: #2271b1;
                        text-decoration: none;
                    }
                    .multiselect-actions a:hover {
                        text-decoration: underline;
                    }

                    /* Options */
                    .multiselect-options {
                        max-height: 200px;
                        overflow-y: auto;
                        padding: 4px;
                    }
                    .multiselect-option {
                        display: flex;
                        align-items: center;
                        padding: 6px 8px;
                        cursor: pointer;
                        border-radius: 2px;
                        font-size: 12px;
                        margin: 0;
                    }
                    .multiselect-option:hover {
                        background: #f0f0f1;
                    }
                    .multiselect-option input[type="checkbox"] {
                        margin: 0 8px 0 0;
                        cursor: pointer;
                    }
                    .multiselect-option span {
                        flex: 1;
                    }
                    .multiselect-option.hidden {
                        display: none;
                    }

                    /* Filter Actions */
                    .filter-actions {
                        display: flex;
                        flex-direction: row;
                        gap: 16px;
                        align-items: center;
                        margin-left: auto;
                    }
                    .filter-actions > label {
                        display: none;
                    }
                    #clear-filters {
                        white-space: nowrap;
                        font-size: 13px;
                        padding: 8px 16px;
                        height: 38px;
                        line-height: 1.5;
                    }
                    #filter-count {
                        font-size: 13px;
                        color: #1d2327;
                        font-weight: 600;
                        white-space: nowrap;
                    }
                    .brand-row.filtered-out,
                    .brand-details.filtered-out {
                        display: none !important;
                    }

                    @media screen and (max-width: 1200px) {
                        .filter-row {
                            flex-wrap: wrap;
                        }
                        .filter-group {
                            min-width: 180px;
                        }
                        .filter-actions {
                            width: 100%;
                            margin-left: 0;
                            margin-top: 8px;
                            justify-content: space-between;
                        }
                    }

                    @media screen and (max-width: 782px) {
                        .filter-group {
                            min-width: 100%;
                            max-width: 100%;
                        }
                        .filter-actions {
                            flex-direction: column;
                            align-items: flex-start;
                            gap: 8px;
                        }
                    }

                    /* Sortable Headers */
                    /* Smooth row visibility transitions */
                    .dataflair-brands-table tbody tr.brand-row {
                        transition: opacity 0.15s ease;
                    }
                    .dataflair-brands-table {
                        transition: opacity 0.1s ease;
                    }
                    .dataflair-brands-table thead th.sortable {
                        padding: 0;
                    }
                    .dataflair-brands-table .sort-link {
                        display: flex;
                        align-items: center;
                        justify-content: space-between;
                        padding: 8px 10px;
                        color: #2c3338;
                        text-decoration: none;
                        cursor: pointer;
                        transition: background-color 0.2s;
                    }
                    .dataflair-brands-table .sort-link:hover {
                        background-color: #f0f0f1;
                    }
                    .sorting-indicator {
                        display: inline-flex;
                        align-items: center;
                        margin-left: 4px;
                    }
                    .sorting-indicator .dashicons {
                        font-size: 16px;
                        width: 16px;
                        height: 16px;
                        color: #a7aaad;
                    }
                    .sort-link.asc .dashicons-sort,
                    .sort-link.desc .dashicons-sort {
                        display: none;
                    }
                    .sort-link.asc .sorting-indicator::after {
                        content: '\f142';
                        font-family: dashicons;
                        font-size: 16px;
                        color: #2271b1;
                    }
                    .sort-link.desc .sorting-indicator::after {
                        content: '\f140';
                        font-family: dashicons;
                        font-size: 16px;
                        color: #2271b1;
                    }

                    /* Brand Rows */
                    .brand-row {
                        border-bottom: 2px solid #dcdcde;
                    }
                    .brand-row:last-of-type {
                        border-bottom: none;
                    }
                    .brand-row.page-hidden,
                    .brand-details.page-hidden {
                        display: none !important;
                    }

                    /* Pagination */
                    .tablenav.bottom {
                        margin-top: 10px;
                    }
                    .tablenav-pages {
                        float: right;
                    }
                    .tablenav-pages .pagination-links {
                        margin-left: 10px;
                    }
                    .tablenav-pages .button[disabled] {
                        color: #a7aaad;
                        cursor: default;
                        pointer-events: none;
                    }
                    .current-page {
                        width: 40px;
                        text-align: center;
                    }

                    .dataflair-brands-table .row-actions {
                        color: #999;
                        font-size: 12px;
                        padding: 2px 0 0;
                    }
                    .dataflair-brands-table .count {
                        display: inline-block;
                        background: #2271b1;
                        color: white;
                        padding: 2px 8px;
                        border-radius: 10px;
                        font-size: 11px;
                        font-weight: 600;
                    }

                    /* Brand Logo */
                    .brand-logo-cell {
                        text-align: center;
                        padding: 8px !important;
                    }
                    .brand-logo-thumb {
                        width: 60px;
                        height: 45px;
                        object-fit: contain;
                        background: #1a1a1a;
                        border-radius: 6px;
                        padding: 6px;
                        display: inline-block;
                    }
                    .brand-logo-placeholder {
                        width: 60px;
                        height: 45px;
                        display: inline-flex;
                        align-items: center;
                        justify-content: center;
                        background: linear-gradient(135deg, #352d67 0%, #5a4fa5 100%);
                        color: #fff;
                        font-size: 14px;
                        font-weight: 700;
                        border-radius: 6px;
                        text-transform: uppercase;
                        letter-spacing: 1px;
                    }

                    /* Toggle Button */
                    .toggle-cell {
                        padding: 8px 4px !important;
                    }
                    .brand-toggle {
                        background: none;
                        border: none;
                        cursor: pointer;
                        padding: 4px;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        transition: transform 0.2s ease;
                    }
                    .brand-toggle:hover {
                        background: #f0f0f1;
                        border-radius: 3px;
                    }
                    .brand-toggle .dashicons {
                        transition: transform 0.2s ease;
                        font-size: 20px;
                        width: 20px;
                        height: 20px;
                    }
                    .brand-toggle[aria-expanded="true"] .dashicons {
                        transform: rotate(90deg);
                    }

                    /* Details Row */
                    .brand-details td {
                        padding: 0 !important;
                        background: #f9f9f9;
                        border-top: none !important;
                    }
                    .brand-details-content {
                        padding: 20px;
                    }

                    /* Details Grid */
                    .details-grid {
                        display: grid;
                        grid-template-columns: repeat(3, 1fr);
                        gap: 20px;
                        margin-bottom: 20px;
                    }
                    .detail-section.full-width {
                        grid-column: 1 / -1;
                    }
                    .detail-section h4 {
                        margin: 0 0 10px 0;
                        color: #1d2327;
                        font-size: 13px;
                        font-weight: 600;
                        text-transform: uppercase;
                        letter-spacing: 0.5px;
                    }
                    .detail-content {
                        font-size: 13px;
                        line-height: 1.6;
                    }

                    /* Badges */
                    .badge-list {
                        display: flex;
                        flex-wrap: wrap;
                        gap: 6px;
                    }
                    .badge {
                        display: inline-block;
                        padding: 4px 10px;
                        background: #e0e0e0;
                        border-radius: 12px;
                        font-size: 11px;
                        font-weight: 500;
                        color: #2c3338;
                    }
                    .badge-gray {
                        background: #f0f0f1;
                        color: #646970;
                    }
                    .badge.more {
                        background: #2271b1;
                        color: white;
                    }
                    .badge.with-tooltip {
                        cursor: help;
                        position: relative;
                    }
                    .badge.with-tooltip:hover {
                        background: #135e96;
                    }
                    .no-data {
                        color: #999;
                        font-style: italic;
                    }
                    .rating {
                        font-size: 16px;
                        font-weight: 600;
                        color: #2271b1;
                    }

                    /* Separator */
                    .details-separator {
                        margin: 20px 0;
                        border: none;
                        border-top: 2px solid #dcdcde;
                    }

                    /* Offers Section */
                    .offers-section h4 {
                        margin: 0 0 12px 0;
                        color: #1d2327;
                        font-size: 14px;
                        font-weight: 600;
                    }
                    .offers-list {
                        display: flex;
                        flex-direction: column;
                        gap: 8px;
                    }
                    .offer-item-inline {
                        display: flex;
                        align-items: center;
                        gap: 8px;
                        padding: 8px 12px;
                        background: white;
                        border: 1px solid #dcdcde;
                        border-radius: 3px;
                        font-size: 12px;
                        line-height: 1.4;
                        flex-wrap: wrap;
                    }
                    .offer-type-badge {
                        display: inline-flex;
                        padding: 3px 10px;
                        background: #2271b1;
                        color: white;
                        border-radius: 10px;
                        font-size: 11px;
                        font-weight: 600;
                        text-transform: uppercase;
                        white-space: nowrap;
                    }
                    .offer-separator {
                        color: #8c8f94;
                        font-weight: 600;
                    }
                    .offer-text-inline {
                        font-weight: 500;
                        color: #1d2327;
                    }
                    .offer-geo-inline {
                        color: #646970;
                    }
                    .offer-tracker-inline {
                        color: #646970;
                    }
                    .tracker-count-inline {
                        background: #00a32a;
                        color: white;
                        padding: 2px 8px;
                        border-radius: 10px;
                        font-weight: 600;
                        font-size: 11px;
                    }
                    .no-trackers-inline {
                        color: #999;
                        font-style: italic;
                    }
                    .offer-extra-info {
                        color: #646970;
                        font-size: 11px;
                    }
                    .badge-free-spins {
                        background: #00a32a;
                        color: white;
                        padding: 2px 8px;
                        border-radius: 10px;
                        font-weight: 600;
                        font-size: 10px;
                        text-transform: uppercase;
                    }
                    .support-available {
                        color: #00a32a;
                        font-weight: 600;
                        font-size: 13px;
                    }

                    @media screen and (max-width: 782px) {
                        .details-grid {
                            grid-template-columns: 1fr;
                        }
                    }
                </style>
            <?php else: ?>
                <p>No brands synced yet. Click "Sync Brands from API" to fetch active brands.</p>
            <?php endif; ?>

            <hr>

            <h2>Brand Details</h2>
            <p>Brands are read-only and automatically synced from the DataFlair API. They are used for managing casino brands across your site.</p>
        </div>
        <?php
    }
}
