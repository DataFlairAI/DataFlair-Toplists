<?php
/**
 * Phase 9.6 (admin UX redesign) — Brands admin page.
 *
 * Replaces the old BrandsPage (Select2 + client-side filter strip) with:
 *   - PageHeader + "Sync Brands" CTA
 *   - Server-side initial render via BrandsRepositoryInterface::findPaginated()
 *   - Search input (debounced 200 ms) + four FilterChips (Licenses, Geos,
 *     Payments, Type) wired to BrandsQueryHandler via AJAX
 *   - Checkbox column + bulk action bar (Re-sync, Apply pattern, Disable/Enable)
 *   - StatusPill for product_types + is_disabled flag
 *   - "incomplete metadata" pill for rows missing product_types
 *   - InlineEditCell for review_url_override
 *   - Sort indicators (▲▼) + server-side pagination (25/page)
 *
 * All dynamic state changes after initial load go through brands.js →
 * dataflair_brands_query AJAX → BrandsQueryHandler.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Pages;

use DataFlair\Toplists\Database\BrandsQuery;
use DataFlair\Toplists\Database\BrandsRepositoryInterface;

final class BrandsPage implements PageInterface
{
    public function __construct(
        private BrandsRepositoryInterface $repo,
        private \Closure $lastSyncLabelFormatter
    ) {}

    public function render(): void
    {
        // Initial page state — no filters, sort by name, page 1.
        $initial_sort    = isset($_GET['sort_by'])  ? sanitize_key((string) $_GET['sort_by'])  : 'name';
        $initial_dir     = isset($_GET['sort_dir']) ? strtoupper((string) $_GET['sort_dir'])   : 'ASC';
        $initial_page    = isset($_GET['paged'])    ? max(1, (int) $_GET['paged'])             : 1;

        $query = BrandsQuery::fromArray([
            'sort_by'  => $initial_sort,
            'sort_dir' => $initial_dir,
            'page'     => $initial_page,
            'per_page' => 25,
        ]);

        $brands_page = $this->repo->findPaginated($query);
        $brands      = $brands_page->rows;
        $total       = $brands_page->total;
        $page_count  = $brands_page->pageCount();

        // Filter chip option lists (run once on page load; AJAX refreshes return these too).
        $filter_licenses      = $this->repo->collectDistinctValuesForFilter('licenses');
        $filter_geos          = $this->repo->collectDistinctValuesForFilter('top_geos');
        $filter_payments      = $this->repo->collectDistinctValuesForFilter('payments');
        $filter_product_types = $this->repo->collectDistinctValuesForFilter('product_types');
        ?>
        <div class="wrap">

            <div class="df-page-header">
                <h1 class="df-page-header__title">Brands</h1>
                <div class="df-page-header__actions">
                    <button type="button" id="dataflair-fetch-all-brands" class="button button-primary">
                        Sync Brands from API
                    </button>
                </div>
            </div>

            <?php settings_errors(); ?>
            <p class="description">Fetches all active brands from the DataFlair API in batches of 25. Existing brands will be updated.</p>
            <p class="description">Sync runs only when triggered here or via WP-CLI. <?php echo esc_html(($this->lastSyncLabelFormatter)('dataflair_last_brands_sync')); ?></p>

            <!-- Sync progress bar (shared with ToplistsListPage) -->
            <div id="dataflair-sync-progress" style="display:none;margin:12px 0;">
                <div style="background:#f0f0f1;border-radius:4px;height:24px;position:relative;overflow:hidden;border:1px solid #dcdcde;">
                    <div id="dataflair-progress-bar"
                         style="background:linear-gradient(90deg,#2271b1,#135e96);height:100%;width:0%;transition:width .3s ease;display:flex;align-items:center;justify-content:center;">
                        <span id="dataflair-progress-text"
                              style="color:#fff;font-size:12px;font-weight:600;position:absolute;left:50%;transform:translateX(-50%);"></span>
                    </div>
                </div>
            </div>
            <p class="description" id="dataflair-fetch-brands-message" style="margin-bottom:12px;">
                <?php echo esc_html(($this->lastSyncLabelFormatter)('dataflair_last_brands_sync')); ?>
            </p>

            <!-- ── Filter bar ─────────────────────────────────────────── -->
            <div class="df-filter-bar" style="display:flex;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px;">
                <input type="search"
                       id="df-brands-search"
                       class="regular-text"
                       placeholder="Search brands…"
                       style="max-width:260px;">

                <?php $this->renderFilterChip('licenses',      'Licenses',        $filter_licenses); ?>
                <?php $this->renderFilterChip('geos',          'Geos',            $filter_geos); ?>
                <?php $this->renderFilterChip('payments',      'Payments',        $filter_payments); ?>
                <?php $this->renderFilterChip('product_types', 'Type',            $filter_product_types); ?>

                <a href="#" id="df-brands-clear-filters"
                   style="display:none;color:#d63638;font-size:13px;text-decoration:none;margin-left:4px;">
                    ✕ Clear filters
                </a>

                <span id="df-brands-count-label"
                      style="margin-left:auto;color:#646970;font-size:13px;">
                    <?php echo esc_html($total); ?> brands
                </span>
            </div>

            <!-- ── Bulk action bar (visible when rows are checked) ────── -->
            <div id="df-bulk-bar" class="df-bulk-bar" style="display:none;">
                <span id="df-bulk-count" style="font-weight:600;">0 selected</span>
                <select id="df-bulk-action" style="margin-left:12px;">
                    <option value="">— Bulk action —</option>
                    <option value="resync">Re-sync selected</option>
                    <option value="apply_pattern">Apply review URL pattern</option>
                    <option value="disable">Disable selected</option>
                    <option value="enable">Enable selected</option>
                </select>
                <input type="text"
                       id="df-bulk-pattern"
                       placeholder="/reviews/{slug}/"
                       style="display:none;margin-left:8px;width:220px;">
                <button type="button" id="df-bulk-apply" class="button button-primary"
                        style="margin-left:8px;">Apply</button>
                <button type="button" id="df-bulk-deselect" class="button"
                        style="margin-left:8px;">Deselect all</button>
            </div>

            <!-- ── Brands table ───────────────────────────────────────── -->
            <table class="wp-list-table widefat striped dataflair-brands-table"
                   id="df-brands-table"
                   style="margin-top:8px;">
                <thead>
                    <tr>
                        <th class="check-column" style="width:3%;">
                            <input type="checkbox" id="df-select-all" title="Select all on this page">
                        </th>
                        <th style="width:28px;"></th>
                        <th style="width:22%;">
                            <?php $this->sortHeader('name', 'Brand', $initial_sort, $initial_dir); ?>
                        </th>
                        <th style="width:10%;">Type</th>
                        <th style="width:8%;">
                            <?php $this->sortHeader('offers_count', 'Offers', $initial_sort, $initial_dir); ?>
                        </th>
                        <th style="width:8%;">
                            <?php $this->sortHeader('trackers_count', 'Trackers', $initial_sort, $initial_dir); ?>
                        </th>
                        <th style="width:11%;">
                            <?php $this->sortHeader('last_synced', 'Last Synced', $initial_sort, $initial_dir); ?>
                        </th>
                        <th style="width:8%;">Status</th>
                        <th style="width:30%;">Review URL</th>
                    </tr>
                </thead>
                <tbody id="df-brands-tbody">
                    <?php $this->renderRows($brands); ?>
                </tbody>
            </table>

            <!-- ── Pagination ─────────────────────────────────────────── -->
            <div id="df-brands-pagination" class="tablenav bottom" style="margin-top:8px;">
                <?php $this->renderPagination($initial_page, $page_count, $total, count($brands)); ?>
            </div>

        </div><!-- /.wrap -->

        <?php $this->renderInlineScript($filter_licenses, $filter_geos, $filter_payments, $filter_product_types,
                                        $initial_sort, $initial_dir, $initial_page, $page_count, $total); ?>
        <?php $this->renderBrandAccordionScript(); ?>
        <?php
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Render a filter chip (label + hidden dropdown checklist).
     *
     * @param string[] $options
     */
    private function renderFilterChip(string $key, string $label, array $options): void
    {
        if (empty($options)) {
            return;
        }
        $id = 'df-chip-' . esc_attr($key);
        ?>
        <div class="df-filter-chip" data-filter="<?php echo esc_attr($key); ?>" style="position:relative;">
            <button type="button"
                    class="button df-filter-chip__trigger"
                    id="<?php echo $id; ?>"
                    aria-expanded="false"
                    aria-haspopup="true">
                <?php echo esc_html($label); ?>
                <span class="df-filter-chip__badge" style="display:none;">0</span>
                <span style="margin-left:4px;">▾</span>
            </button>
            <div class="df-filter-chip__dropdown"
                 role="listbox"
                 aria-multiselectable="true"
                 aria-labelledby="<?php echo $id; ?>"
                 style="display:none;position:absolute;top:100%;left:0;min-width:200px;
                        background:#fff;border:1px solid #dcdcde;border-radius:4px;
                        box-shadow:0 2px 8px rgba(0,0,0,.15);z-index:100;padding:8px 0;max-height:260px;overflow-y:auto;">
                <?php foreach ($options as $val): ?>
                    <label style="display:flex;align-items:center;padding:4px 12px;cursor:pointer;gap:8px;font-size:13px;">
                        <input type="checkbox"
                               class="df-chip-option"
                               value="<?php echo esc_attr($val); ?>">
                        <?php echo esc_html($val); ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render a sortable column header with ▲▼ indicators.
     */
    private function sortHeader(string $col, string $label, string $active_col, string $active_dir): void
    {
        $indicator = '';
        if ($col === $active_col) {
            $indicator = $active_dir === 'ASC' ? ' ▲' : ' ▼';
        }
        echo '<a href="#" class="df-sort-link" data-sort="' . esc_attr($col) . '">'
            . esc_html($label) . '<span class="df-sort-indicator" aria-hidden="true">'
            . esc_html($indicator) . '</span></a>';
    }

    /**
     * Render tbody rows from an array of brand row arrays.
     *
     * @param array<int,array<string,mixed>> $brands
     */
    private function renderRows(array $brands): void
    {
        if (empty($brands)) {
            echo '<tr><td colspan="9" style="text-align:center;padding:24px;color:#646970;">'
               . 'No brands found. Try adjusting your filters or sync brands from the API.'
               . '</td></tr>';
            return;
        }
        foreach ($brands as $b) {
            $this->renderRow($b);
        }
    }

    /**
     * @param array<string,mixed> $b
     */
    private function renderRow(array $b): void
    {
        $api_id       = (int) ($b['api_brand_id'] ?? 0);
        $name         = (string) ($b['name']         ?? '');
        $slug         = (string) ($b['slug']         ?? '');
        $product_type = (string) ($b['product_types'] ?? '');
        $offers       = (int)    ($b['offers_count']  ?? 0);
        $trackers     = (int)    ($b['trackers_count'] ?? 0);
        $last_synced  = (string) ($b['last_synced']   ?? '');
        $is_disabled  = (bool)   ($b['is_disabled']   ?? false);
        $review_url   = (string) ($b['review_url_override'] ?? '');
        $logo         = (string) ($b['local_logo_url'] ?? '');
        $initials     = strtoupper(mb_substr($name, 0, 2));

        // "Incomplete metadata" = no product type recorded yet.
        $incomplete = $product_type === '';

        $last_synced_fmt = $last_synced ? esc_html(date('Y-m-d', strtotime($last_synced))) : '—';
        ?>
        <tr class="df-brand-row <?php echo $is_disabled ? 'df-brand-row--disabled' : ''; ?>"
            data-brand-id="<?php echo esc_attr($api_id); ?>">
            <td class="check-column">
                <input type="checkbox" class="df-brand-check" value="<?php echo esc_attr($api_id); ?>">
            </td>
            <td style="width:28px;padding:0 4px;vertical-align:middle;">
                <button type="button" class="df-brand-toggle-btn button-link"
                        data-brand-id="<?php echo esc_attr($api_id); ?>"
                        title="View details"
                        style="padding:2px 4px;cursor:pointer;color:#646970;">
                    <span class="dashicons dashicons-arrow-right" style="font-size:16px;width:16px;height:16px;line-height:16px;"></span>
                </button>
            </td>
            <td class="df-brand-identity-cell" style="padding-left:1rem;">
                <div style="display:flex;align-items:center;gap:10px;">
                    <div class="df-brand-logo-wrap" style="flex-shrink:0;">
                        <?php if ($logo): ?>
                            <img src="<?php echo esc_url($logo); ?>"
                                 alt=""
                                 class="brand-logo-thumb"
                                 style="width:32px;height:32px;object-fit:contain;border-radius:3px;"
                                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                            <div class="df-brand-initials"
                                 style="display:none;width:32px;height:32px;background:#e0e0e0;border-radius:3px;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#444;">
                                <?php echo esc_html($initials); ?>
                            </div>
                        <?php else: ?>
                            <div class="df-brand-initials"
                                 style="width:32px;height:32px;background:#e0e0e0;border-radius:3px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#444;">
                                <?php echo esc_html($initials); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <strong><?php echo esc_html($name); ?></strong>
                        <?php if ($incomplete): ?>
                            <span class="df-pill df-pill--warning" style="font-size:10px;margin-left:6px;">incomplete metadata</span>
                        <?php endif; ?>
                        <br>
                        <span style="color:#646970;font-size:12px;font-family:monospace;">
                            <?php echo esc_html($slug); ?> <span style="color:#aaa;">#<?php echo esc_html($api_id); ?></span>
                        </span>
                    </div>
                </div>
            </td>
            <td>
                <?php if ($product_type): ?>
                    <span class="df-pill df-pill--info"><?php echo esc_html($product_type); ?></span>
                <?php else: ?>
                    <span style="color:#aaa;">—</span>
                <?php endif; ?>
            </td>
            <td style="text-align:center;"><?php echo esc_html($offers > 0 ? $offers : '0'); ?></td>
            <td style="text-align:center;"><?php echo esc_html($trackers > 0 ? $trackers : '0'); ?></td>
            <td><?php echo $last_synced_fmt; ?></td>
            <td>
                <?php if ($is_disabled): ?>
                    <span class="df-pill df-pill--gray">Disabled</span>
                <?php else: ?>
                    <span class="df-pill df-pill--success">Active</span>
                <?php endif; ?>
            </td>
            <td class="df-review-url-cell" style="min-width:200px;">
                <span class="df-inline-display" style="color:<?php echo $review_url ? '#000' : '#aaa'; ?>;">
                    <?php echo $review_url ? esc_html($review_url) : '<em>not set</em>'; ?>
                </span>
                <input type="text"
                       class="df-inline-input df-brand-review-url"
                       data-brand-id="<?php echo esc_attr($api_id); ?>"
                       value="<?php echo esc_attr($review_url); ?>"
                       placeholder="/reviews/<?php echo esc_attr($slug ?: 'brand-slug'); ?>/"
                       style="display:none;width:100%;margin-top:4px;">
                <div style="margin-top:4px;display:flex;gap:4px;">
                    <button type="button"
                            class="button button-small df-inline-edit-btn"
                            data-brand-id="<?php echo esc_attr($api_id); ?>">Edit</button>
                    <button type="button"
                            class="button button-small button-primary df-inline-save-btn"
                            data-brand-id="<?php echo esc_attr($api_id); ?>"
                            style="display:none;">Save</button>
                    <button type="button"
                            class="button button-small df-inline-cancel-btn"
                            data-brand-id="<?php echo esc_attr($api_id); ?>"
                            style="display:none;">Cancel</button>
                </div>
            </td>
        </tr>
        <!-- Brand accordion row (lazy-loaded on first expand) -->
        <tr class="df-brand-accordion-row" id="df-bacc-<?php echo esc_attr($api_id); ?>" style="display:none;">
            <td colspan="9" style="padding:0;background:#f6f7f7;">
                <div class="df-brand-accordion-inner" style="padding:16px 20px;">
                    <hr style="margin:0 0 14px;border:none;border-top:1px solid #dcdcde;">
                    <div class="df-bacc-loading" style="color:#646970;">Loading details…</div>
                    <div class="df-bacc-content" style="display:none;">

                        <!-- Info pills -->
                        <div class="df-bacc-section" style="margin-bottom:14px;">
                            <div class="df-bacc-row" style="display:flex;flex-wrap:wrap;gap:8px;align-items:baseline;margin-bottom:6px;">
                                <strong style="width:110px;flex-shrink:0;">Type:</strong>
                                <span class="df-bacc-product-types" style="display:flex;flex-wrap:wrap;gap:4px;"></span>
                            </div>
                            <div class="df-bacc-row" style="display:flex;flex-wrap:wrap;gap:8px;align-items:baseline;margin-bottom:6px;">
                                <strong style="width:110px;flex-shrink:0;">Licenses:</strong>
                                <span class="df-bacc-licenses" style="display:flex;flex-wrap:wrap;gap:4px;"></span>
                            </div>
                            <div class="df-bacc-row" style="display:flex;flex-wrap:wrap;gap:8px;align-items:baseline;margin-bottom:6px;">
                                <strong style="width:110px;flex-shrink:0;">Payments:</strong>
                                <span class="df-bacc-payments" style="display:flex;flex-wrap:wrap;gap:4px;"></span>
                            </div>
                            <div class="df-bacc-row" style="display:flex;flex-wrap:wrap;gap:8px;align-items:baseline;">
                                <strong style="width:110px;flex-shrink:0;">Games:</strong>
                                <span class="df-bacc-game-types" style="display:flex;flex-wrap:wrap;gap:4px;"></span>
                            </div>
                        </div>

                        <!-- Geos -->
                        <div class="df-bacc-section" style="margin-bottom:14px;">
                            <div class="df-bacc-row" style="display:flex;flex-wrap:wrap;gap:8px;align-items:baseline;margin-bottom:6px;">
                                <strong style="width:110px;flex-shrink:0;">Top Geos:</strong>
                                <span class="df-bacc-top-geos" style="display:flex;flex-wrap:wrap;gap:4px;"></span>
                            </div>
                            <div class="df-bacc-row" style="display:flex;flex-wrap:wrap;gap:8px;align-items:baseline;">
                                <strong style="width:110px;flex-shrink:0;">Restricted:</strong>
                                <span class="df-bacc-restricted" style="display:flex;flex-wrap:wrap;gap:4px;"></span>
                                <a href="#" class="df-bacc-restricted-toggle" style="font-size:12px;display:none;"></a>
                            </div>
                        </div>

                        <!-- Offers + trackers -->
                        <div class="df-bacc-section">
                            <strong style="display:block;margin-bottom:8px;">Offers &amp; Tracking Links</strong>
                            <div class="df-bacc-no-offers" style="color:#646970;display:none;">No offers attached to this brand.</div>
                            <table class="df-bacc-offers-table widefat" style="display:none;">
                                <thead>
                                    <tr>
                                        <th style="width:5%;">#</th>
                                        <th style="width:28%;">Offer Text</th>
                                        <th style="width:15%;">Geo</th>
                                        <th style="width:20%;">Campaign</th>
                                        <th>Affiliate Link</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>

                    </div>
                </div>
            </td>
        </tr>
        <?php
    }

    /**
     * Render the pagination nav.
     */
    private function renderPagination(int $page, int $pages, int $total, int $on_page): void
    {
        ?>
        <div class="tablenav-pages" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <span class="displaying-num" id="df-brands-paging-label">
                <?php printf(
                    esc_html__('%1$s brands — page %2$d of %3$d'),
                    number_format_i18n($total),
                    $page,
                    $pages
                ); ?>
            </span>
            <span class="pagination-links">
                <button type="button" class="button df-page-btn" data-page="1"
                        <?php echo $page <= 1 ? 'disabled' : ''; ?>>«</button>
                <button type="button" class="button df-page-btn" data-page="<?php echo max(1, $page - 1); ?>"
                        <?php echo $page <= 1 ? 'disabled' : ''; ?>>‹</button>
                <span style="padding:0 8px;">Page <?php echo esc_html($page); ?> of <?php echo esc_html($pages); ?></span>
                <button type="button" class="button df-page-btn" data-page="<?php echo min($pages, $page + 1); ?>"
                        <?php echo $page >= $pages ? 'disabled' : ''; ?>>›</button>
                <button type="button" class="button df-page-btn" data-page="<?php echo esc_attr($pages); ?>"
                        <?php echo $page >= $pages ? 'disabled' : ''; ?>>»</button>
            </span>
        </div>
        <?php
    }

    /**
     * Output inline JS that boots brands.js with initial state + nonces.
     *
     * @param string[] $filterLicenses
     * @param string[] $filterGeos
     * @param string[] $filterPayments
     * @param string[] $filterProductTypes
     */
    private function renderInlineScript(
        array $filterLicenses,
        array $filterGeos,
        array $filterPayments,
        array $filterProductTypes,
        string $sortBy,
        string $sortDir,
        int $page,
        int $pages,
        int $total
    ): void {
        ?>
        <script>
        window.DFBrands = <?php echo json_encode([
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'nonces' => [
                'query'           => wp_create_nonce('dataflair_brands_query'),
                'brandDetails'    => wp_create_nonce('dataflair_brand_details'),
                'disableBrands'   => wp_create_nonce('dataflair_bulk_disable_brands'),
                'resyncBrands'    => wp_create_nonce('dataflair_bulk_resync_brands'),
                'applyPattern'    => wp_create_nonce('dataflair_bulk_apply_review_pattern'),
                'saveReviewUrl'   => wp_create_nonce('dataflair_save_review_url'),
                'fetchBrands'     => wp_create_nonce('dataflair_fetch_all_brands'),
                'syncBrandsBatch' => wp_create_nonce('dataflair_sync_brands_batch'),
            ],
            'state' => [
                'search'       => '',
                'licenses'     => [],
                'geos'         => [],
                'payments'     => [],
                'product_types' => [],
                'sort_by'      => $sortBy,
                'sort_dir'     => $sortDir,
                'page'         => $page,
                'pages'        => $pages,
                'total'        => $total,
                'per_page'     => 25,
            ],
            'filterOptions' => [
                'licenses'      => $filterLicenses,
                'geos'          => $filterGeos,
                'payments'      => $filterPayments,
                'product_types' => $filterProductTypes,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
        </script>
        <?php
    }

    private function renderBrandAccordionScript(): void
    {
        ?>
        <script>
        jQuery(document).ready(function ($) {
            var ajaxUrl = window.DFBrands.ajaxUrl;
            var nonce   = window.DFBrands.nonces.brandDetails;
            var cache   = {};

            function pill(text, cls) {
                cls = cls || 'df-pill--info';
                return '<span class="df-pill ' + cls + '" style="font-size:11px;padding:1px 6px;">' + $('<span>').text(text).html() + '</span>';
            }

            function renderPills($el, arr, cls) {
                if (!arr || !arr.length) { $el.html('<span style="color:#999;">—</span>'); return; }
                $el.html(arr.map(function (v) { return pill(v, cls || 'df-pill--info'); }).join(' '));
            }

            function loadBrandDetails($row, brandId) {
                var $acc  = $('#df-bacc-' + brandId);
                var $load = $acc.find('.df-bacc-loading');
                var $body = $acc.find('.df-bacc-content');
                $load.show(); $body.hide();

                $.post(ajaxUrl, { action: 'dataflair_brand_details', _ajax_nonce: nonce, api_brand_id: brandId }, function (res) {
                    $load.hide();
                    if (!res || !res.success) { $load.text('Failed to load details.').show(); return; }
                    var d = res.data;

                    renderPills($acc.find('.df-bacc-product-types'), d.product_types, 'df-pill--info');
                    renderPills($acc.find('.df-bacc-licenses'), d.licenses, 'df-pill--success');
                    renderPills($acc.find('.df-bacc-payments'), d.payment_methods, 'df-pill--gray');
                    renderPills($acc.find('.df-bacc-game-types'), d.game_types, 'df-pill--gray');
                    renderPills($acc.find('.df-bacc-top-geos'), d.top_geos, 'df-pill--info');

                    // Restricted countries — show first 8, "show X more" toggle.
                    var restricted = d.restricted_countries || [];
                    var $rest = $acc.find('.df-bacc-restricted');
                    var $toggle = $acc.find('.df-bacc-restricted-toggle');
                    if (!restricted.length) {
                        $rest.html('<span style="color:#999;">None</span>');
                    } else {
                        var visible = restricted.slice(0, 8);
                        var hidden  = restricted.slice(8);
                        $rest.html(visible.map(function (v) { return pill(v, 'df-pill--error'); }).join(' '));
                        if (hidden.length) {
                            $toggle.text('+ ' + hidden.length + ' more').show();
                            $toggle.off('click').on('click', function (e) {
                                e.preventDefault();
                                if ($toggle.data('expanded')) {
                                    $rest.html(visible.map(function (v) { return pill(v, 'df-pill--error'); }).join(' '));
                                    $toggle.text('+ ' + hidden.length + ' more').data('expanded', false);
                                } else {
                                    $rest.html(restricted.map(function (v) { return pill(v, 'df-pill--error'); }).join(' '));
                                    $toggle.text('− show less').data('expanded', true);
                                }
                            });
                        }
                    }

                    // Offers table.
                    var offers = d.offers || [];
                    if (!offers.length) {
                        $acc.find('.df-bacc-no-offers').show();
                    } else {
                        var rows = [];
                        var rowNum = 0;
                        offers.forEach(function (offer) {
                            var geoStr  = (offer.geo_countries || []).join(', ') || '—';
                            var offerText = offer.offer_text || '—';
                            if (!offer.trackers || !offer.trackers.length) {
                                rowNum++;
                                rows.push('<tr>'
                                    + '<td>' + rowNum + '</td>'
                                    + '<td title="' + $('<span>').text(offerText).html() + '">' + $('<span>').text(offerText).html() + '</td>'
                                    + '<td>' + $('<span>').text(geoStr).html() + '</td>'
                                    + '<td style="color:#999;">—</td>'
                                    + '<td style="color:#999;">—</td>'
                                    + '</tr>');
                            } else {
                                offer.trackers.forEach(function (t) {
                                    rowNum++;
                                    var link = t.tracker_link
                                        ? '<a href="' + $('<span>').text(t.tracker_link).html() + '" target="_blank" rel="noopener" style="font-size:12px;word-break:break-all;">'
                                          + $('<span>').text(t.tracker_link.substring(0, 60) + (t.tracker_link.length > 60 ? '…' : '')).html() + '</a>'
                                        : '<span style="color:#999;">—</span>';
                                    rows.push('<tr>'
                                        + '<td>' + rowNum + '</td>'
                                        + '<td title="' + $('<span>').text(offerText).html() + '">' + $('<span>').text(offerText).html() + '</td>'
                                        + '<td>' + $('<span>').text(geoStr).html() + '</td>'
                                        + '<td style="font-size:12px;">' + $('<span>').text(t.campaign_name || '—').html() + '</td>'
                                        + '<td>' + link + '</td>'
                                        + '</tr>');
                                });
                            }
                        });
                        $acc.find('.df-bacc-offers-table tbody').html(rows.join(''));
                        $acc.find('.df-bacc-offers-table').show();
                    }

                    $body.show();
                    cache[brandId] = true;
                });
            }

            $(document).on('click', '.df-brand-toggle-btn', function () {
                var brandId = $(this).data('brand-id');
                var $acc    = $('#df-bacc-' + brandId);
                var open    = $acc.is(':visible');
                $acc.toggle(!open);
                $(this).find('.dashicons')
                    .toggleClass('dashicons-arrow-right', open)
                    .toggleClass('dashicons-arrow-down', !open);
                if (!open && !cache[brandId]) {
                    loadBrandDetails($(this).closest('tr'), brandId);
                }
            });
        });
        </script>
        <?php
    }
}
