<?php
/**
 * Phase 9.6 (admin UX redesign) — Admin page listing all synced toplists.
 *
 * Features:
 *   - Search input + template filter chip
 *   - Bulk action bar (Re-sync selected, Delete selected)
 *   - Sortable columns, pagination
 *   - Per-row accordion with Items tab (lazy AJAX) + Raw JSON tab + Alt Geos tab
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Pages;

final class ToplistsListPage implements PageInterface
{
    public function __construct(private \Closure $lastSyncLabelFormatter) {}

    public function render(): void
    {
        global $wpdb;
        $table_name = $wpdb->prefix . DATAFLAIR_TABLE_NAME;

        $per_page = 25;
        $paged    = max(1, (int) ($_GET['paged'] ?? 1));
        $total    = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        $total_pages = $total > 0 ? (int) ceil($total / $per_page) : 1;
        $paged    = min($paged, $total_pages);
        $offset   = ($paged - 1) * $per_page;

        $toplists = $wpdb->get_results($wpdb->prepare(
            "SELECT id, api_toplist_id, name, slug, version,
                    last_synced, item_count, locked_count, sync_warnings,
                    current_period,
                    JSON_UNQUOTE(JSON_EXTRACT(data, '$.data.template.name')) AS template_name
             FROM $table_name
             ORDER BY api_toplist_id ASC
             LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));

        // All rows for the Alt Geos dropdown (name + IDs only).
        $all_toplists_for_select = $wpdb->get_results(
            "SELECT id, api_toplist_id, name FROM $table_name ORDER BY name ASC"
        );

        // Template filter built from every row, not just current page.
        $template_rows = $wpdb->get_col(
            "SELECT DISTINCT JSON_UNQUOTE(JSON_EXTRACT(data, '$.data.template.name'))
             FROM $table_name
             WHERE JSON_EXTRACT(data, '$.data.template.name') IS NOT NULL"
        );
        $all_templates = array_values(array_filter($template_rows ?? []));
        sort($all_templates);
        ?>
        <div class="wrap">
            <div class="df-page-header">
                <h1 class="df-page-header__title">Toplists</h1>
                <div class="df-page-header__actions">
                    <button type="button" id="dataflair-fetch-all-toplists" class="button button-primary">
                        Fetch All Toplists from API
                    </button>
                </div>
            </div>

            <!-- Sync console (same UX as Dashboard) -->
            <div id="df-tl-sync-console" class="df-sync-console" data-active="0" style="display:none;margin-bottom:16px;">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                    <span id="df-tl-spinner" style="animation:df-spin 1s linear infinite;display:inline-block;">↻</span>
                    <strong id="df-tl-title">Syncing Toplists…</strong>
                    <span id="df-tl-eta" style="margin-left:auto;color:#646970;font-size:12px;"></span>
                </div>
                <div style="height:6px;background:#ddd;border-radius:3px;margin-bottom:8px;">
                    <div id="df-tl-progress-bar" style="height:100%;background:#2271b1;width:0;border-radius:3px;transition:width 0.3s;"></div>
                </div>
                <div id="df-tl-stats" style="font-size:12px;color:#646970;margin-bottom:6px;">Starting…</div>
                <div id="df-tl-log" style="font-family:monospace;font-size:11px;background:#1e1e1e;color:#d4d4d4;padding:10px 12px;border-radius:4px;max-height:200px;overflow-y:auto;"></div>
            </div>
            <p class="description"><?php echo esc_html(($this->lastSyncLabelFormatter)('dataflair_last_toplists_sync')); ?></p>
            <hr>

            <?php if ($toplists): ?>
                <!-- Search + Filters -->
                <div class="df-toolbar" style="display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
                    <input type="text" id="df-toplist-search" class="regular-text" placeholder="Search by name or slug…" style="max-width:280px;">
                    <div class="filter-group">
                        <label style="font-weight:600;margin-right:4px;">Template:</label>
                        <select id="dataflair-filter-template">
                            <option value="">All</option>
                            <?php foreach ($all_templates as $tname): ?>
                                <option value="<?php echo esc_attr($tname); ?>"><?php echo esc_html($tname); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="button" id="dataflair-clear-toplist-filters" class="button">Clear</button>
                    <span id="dataflair-toplists-count" style="color:#646970;margin-left:auto;">
                        <?php echo esc_html($total); ?> toplists total · page <?php echo $paged; ?> of <?php echo $total_pages; ?>
                    </span>
                </div>

                <!-- Bulk action bar -->
                <div id="df-toplist-bulk-bar" style="display:none;background:#f0f6fc;border:1px solid #c5d9f1;border-radius:3px;padding:8px 12px;margin-bottom:12px;display:none;align-items:center;gap:12px;">
                    <span id="df-toplist-selected-count" style="font-weight:600;"></span>
                    <button type="button" id="df-toplist-bulk-resync" class="button">Re-sync Selected</button>
                    <button type="button" id="df-toplist-bulk-delete" class="button" style="color:#b32d2e;">Delete Selected</button>
                </div>

                <table class="wp-list-table widefat fixed striped dataflair-toplists-table">
                    <thead>
                        <tr>
                            <th style="width:30px;"><input type="checkbox" id="df-toplist-select-all" title="Select all"></th>
                            <th style="width:36px;"></th>
                            <th style="width:60px;">WP ID</th>
                            <th>API ID</th>
                            <th style="width:38%;">Name</th>
                            <th class="sortable-toplist">
                                <a href="#" class="toplist-sort-link" data-sort="template">Template <span class="toplist-sort-indicator"></span></a>
                            </th>
                            <th>Period</th>
                            <th class="sortable-toplist">
                                <a href="#" class="toplist-sort-link" data-sort="items">Items <span class="toplist-sort-indicator"></span></a>
                            </th>
                            <th class="sortable-toplist">
                                <a href="#" class="toplist-sort-link" data-sort="last_synced">Last Synced <span class="toplist-sort-indicator"></span></a>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($toplists as $toplist):
                            $template_name = isset($toplist->template_name) ? (string) $toplist->template_name : '';
                            $items_count   = isset($toplist->item_count)    ? (int)    $toplist->item_count    : 0;
                            $sync_warnings_arr = (!empty($toplist->sync_warnings)) ? json_decode($toplist->sync_warnings, true) : [];
                            $warning_count     = is_array($sync_warnings_arr) ? count($sync_warnings_arr) : 0;
                            $last_synced_ts    = $toplist->last_synced ? strtotime($toplist->last_synced) : 0;
                            $is_stale          = $last_synced_ts && (time() - $last_synced_ts) > 3600;
                        ?>
                        <tr class="toplist-row"
                            data-toplist-id="<?php echo esc_attr($toplist->id); ?>"
                            data-api-toplist-id="<?php echo esc_attr($toplist->api_toplist_id); ?>"
                            data-template="<?php echo esc_attr($template_name); ?>"
                            data-items="<?php echo esc_attr($items_count); ?>"
                            data-last-synced="<?php echo esc_attr($toplist->last_synced); ?>"
                            data-name="<?php echo esc_attr(strtolower($toplist->name)); ?>"
                            data-slug="<?php echo esc_attr(strtolower($toplist->slug)); ?>">
                            <td><input type="checkbox" class="df-toplist-check" value="<?php echo esc_attr($toplist->api_toplist_id); ?>"></td>
                            <td>
                                <button type="button" class="toplist-toggle-btn" data-api-id="<?php echo esc_attr($toplist->api_toplist_id); ?>" title="View Details">
                                    <span class="dashicons dashicons-arrow-right"></span>
                                </button>
                            </td>
                            <td style="font-family:monospace;color:#646970;"><?php echo esc_html($toplist->id); ?></td>
                            <td style="font-family:monospace;"><?php echo esc_html($toplist->api_toplist_id); ?></td>
                            <td>
                                <?php echo esc_html($toplist->name); ?>
                                <?php if ($warning_count > 0): ?>
                                    <span class="df-pill df-pill--warning" title="<?php echo esc_attr($warning_count . ' sync warning(s)'); ?>"><?php echo $warning_count; ?> ⚠</span>
                                <?php endif; ?>
                                <?php if ($is_stale): ?>
                                    <span class="df-pill df-pill--error" title="Last sync was over 1 hour ago">Stale</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($template_name ?: '—'); ?></td>
                            <td><?php echo !empty($toplist->current_period) ? esc_html($toplist->current_period) : '—'; ?></td>
                            <td><?php echo esc_html($items_count); ?></td>
                            <td><?php echo esc_html($toplist->last_synced ?: '—'); ?></td>
                        </tr>
                        <!-- Accordion row -->
                        <tr class="toplist-accordion-row" id="acc-<?php echo esc_attr($toplist->id); ?>" style="display:none;">
                            <td colspan="9" style="padding:0;">
                                <div class="toplist-accordion-inner">
                                    <nav class="df-accordion-tabs">
                                        <button type="button" class="df-acc-tab df-acc-tab--active" data-tab="items">Items</button>
                                        <button type="button" class="df-acc-tab" data-tab="json">Raw JSON</button>
                                        <button type="button" class="df-acc-tab" data-tab="altgeos">Alt Geos</button>
                                        <span class="df-acc-meta">Last synced: <strong><?php echo esc_html($toplist->last_synced ?: '—'); ?></strong></span>
                                        <button type="button" class="button button-small df-acc-resync" data-api-id="<?php echo esc_attr($toplist->api_toplist_id); ?>" style="margin-left:auto;">↻ Re-sync</button>
                                    </nav>

                                    <!-- Items tab -->
                                    <div class="df-acc-panel df-acc-panel--active" data-panel="items">
                                        <div class="df-acc-items-loading">Loading items…</div>
                                        <table class="df-acc-items-table" style="display:none;">
                                            <thead><tr><th style="width:36px;">#</th><th style="width:22%;">Brand</th><th style="width:28%;">Bonus Offer</th><th>Affiliate Link</th><th style="width:80px;">Status</th></tr></thead>
                                            <tbody></tbody>
                                        </table>
                                        <p class="df-acc-items-empty" style="display:none;">No items in this toplist.</p>
                                    </div>

                                    <!-- Raw JSON tab -->
                                    <div class="df-acc-panel" data-panel="json" style="display:none;">
                                        <div class="df-acc-json-toolbar">
                                            <button type="button" class="button button-small df-json-copy">Copy</button>
                                            <a href="#" class="button button-small df-json-download" data-api-id="<?php echo esc_attr($toplist->api_toplist_id); ?>">Download .json</a>
                                        </div>
                                        <div class="df-acc-json-loading">Loading JSON…</div>
                                        <pre class="df-acc-json-code" style="display:none;"></pre>
                                    </div>

                                    <!-- Alt Geos tab -->
                                    <div class="df-acc-panel" data-panel="altgeos" style="display:none;">
                                        <div class="alternative-toplists-list"></div>
                                        <div class="add-alternative-toplist" style="margin-top:16px;padding:15px;background:#fff;border:1px solid #ddd;">
                                            <h4 style="margin-top:0;">Add Alternative Toplist</h4>
                                            <table class="form-table" style="margin:0;">
                                                <tr>
                                                    <th><label>Geo / Market</label></th>
                                                    <td>
                                                        <select class="alt-geo-select" style="min-width:200px;">
                                                            <option value="">Select a geo…</option>
                                                        </select>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <th><label>Alternative Toplist</label></th>
                                                    <td>
                                                        <select class="alt-toplist-select" style="min-width:300px;">
                                                            <option value="">Select a toplist…</option>
                                                            <?php foreach ($all_toplists_for_select as $alt): ?>
                                                                <option value="<?php echo esc_attr($alt->id); ?>">
                                                                    <?php echo esc_html($alt->name); ?> (#<?php echo esc_html($alt->api_toplist_id); ?>)
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </td>
                                                </tr>
                                            </table>
                                            <button type="button" class="button button-primary save-alternative-toplist">Add Alternative</button>
                                            <span class="alt-save-message" style="margin-left:10px;"></span>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($total_pages > 1):
                    $base_url = admin_url('admin.php?page=dataflair-toplists-list');
                ?>
                <div style="display:flex;align-items:center;gap:6px;margin-top:16px;flex-wrap:wrap;">
                    <span style="color:#646970;margin-right:8px;"><?php echo esc_html($total); ?> toplists · page <?php echo $paged; ?> of <?php echo $total_pages; ?></span>
                    <a href="<?php echo esc_url($base_url . '&paged=1'); ?>" class="button button-small" <?php echo $paged <= 1 ? 'disabled' : ''; ?>>«</a>
                    <a href="<?php echo esc_url($base_url . '&paged=' . max(1, $paged - 1)); ?>" class="button button-small" <?php echo $paged <= 1 ? 'disabled' : ''; ?>>‹ Prev</a>
                    <a href="<?php echo esc_url($base_url . '&paged=' . min($total_pages, $paged + 1)); ?>" class="button button-small" <?php echo $paged >= $total_pages ? 'disabled' : ''; ?>>Next ›</a>
                    <a href="<?php echo esc_url($base_url . '&paged=' . $total_pages); ?>" class="button button-small" <?php echo $paged >= $total_pages ? 'disabled' : ''; ?>>»</a>
                </div>
                <?php endif; ?>

            <?php else: ?>
                <p class="df-empty-state">No toplists synced yet. Click <strong>Fetch All Toplists from API</strong> to get started.</p>
            <?php endif; ?>

            <hr>
            <h2>Shortcode Usage</h2>
            <p>By ID: <code>[dataflair_toplist id="3" title="Best UK Casinos" limit="5"]</code></p>
            <p>By slug: <code>[dataflair_toplist slug="brazil-casinos"]</code></p>
        </div>

        <?php $this->renderInlineScript(); ?>
        <?php
    }

    private function renderInlineScript(): void
    {
        ?>
        <script>
        jQuery(document).ready(function ($) {
            var ajaxUrl = <?php echo json_encode(admin_url('admin-ajax.php')); ?>;
            var nonces = {
                accordion:      <?php echo json_encode(wp_create_nonce('dataflair_toplist_accordion_details')); ?>,
                rawJson:        <?php echo json_encode(wp_create_nonce('dataflair_toplist_raw_json')); ?>,
                bulkResync:     <?php echo json_encode(wp_create_nonce('dataflair_bulk_resync_toplists')); ?>,
                bulkDelete:     <?php echo json_encode(wp_create_nonce('dataflair_bulk_delete_toplists')); ?>,
                fetchAll:       <?php echo json_encode(wp_create_nonce('dataflair_fetch_all_toplists')); ?>,
                syncBatch:      <?php echo json_encode(wp_create_nonce('dataflair_sync_toplists_batch')); ?>,
            };

            // In-memory cache: api_toplist_id → { items, json }
            var detailCache = {};

            /* ── Accordion toggle ─────────────────────────────────── */
            $(document).on('click', '.toplist-toggle-btn', function () {
                var $btn  = $(this);
                var rowId = $(this).closest('tr').data('toplist-id');
                var apiId = $(this).data('api-id');
                var $acc  = $('#acc-' + rowId);
                var open  = $acc.is(':visible');
                $acc.toggle(!open);
                $btn.find('.dashicons').toggleClass('dashicons-arrow-right', open).toggleClass('dashicons-arrow-down', !open);
                if (!open && !detailCache[apiId]) {
                    loadItems($acc, apiId);
                }
            });

            /* ── Tab switching ────────────────────────────────────── */
            $(document).on('click', '.df-acc-tab', function () {
                var $tab = $(this);
                var $acc = $tab.closest('.toplist-accordion-inner');
                var panel = $tab.data('tab');
                var apiId = $acc.closest('tr').find('.df-acc-resync').data('api-id') ||
                            $acc.closest('tr').prev().data('api-toplist-id');

                $acc.find('.df-acc-tab').removeClass('df-acc-tab--active');
                $tab.addClass('df-acc-tab--active');
                $acc.find('.df-acc-panel').hide();
                $acc.find('[data-panel="' + panel + '"]').show();

                if (panel === 'json' && detailCache[apiId] && !detailCache[apiId].jsonLoaded) {
                    loadJson($acc, apiId);
                }
            });

            /* ── Load items (lazy) ────────────────────────────────── */
            function loadItems($acc, apiId) {
                var $panel = $acc.find('[data-panel="items"]');
                $panel.find('.df-acc-items-loading').show();
                $panel.find('.df-acc-items-table, .df-acc-items-empty').hide();

                $.post(ajaxUrl, { action: 'dataflair_toplist_accordion_details', _ajax_nonce: nonces.accordion, api_toplist_id: apiId }, function (res) {
                    $panel.find('.df-acc-items-loading').hide();
                    if (!res.success) { $panel.find('.df-acc-items-empty').text('Failed to load items.').show(); return; }
                    var items = (res.data || {}).items || [];
                    if (!items.length) { $panel.find('.df-acc-items-empty').show(); return; }
                    var rows = items.map(function (item) {
                        var pillCls = item.status === 'synced' ? 'df-pill--success' : 'df-pill--warning';
                        var affLink = item.affiliate_link
                            ? '<a href="' + esc(item.affiliate_link) + '" target="_blank" rel="noopener" style="font-size:12px;word-break:break-all;">'
                              + esc(item.affiliate_link.substring(0, 55) + (item.affiliate_link.length > 55 ? '…' : '')) + '</a>'
                            : '<span style="color:#999;">—</span>';
                        return '<tr><td style="font-family:monospace;">' + item.position + '</td>'
                            + '<td>' + esc(item.brand_name) + ' <span style="color:#999;font-size:11px;">#' + item.brand_id + '</span></td>'
                            + '<td title="' + esc(item.bonus_offer) + '">' + esc(item.bonus_offer || '—') + '</td>'
                            + '<td>' + affLink + '</td>'
                            + '<td><span class="df-pill ' + pillCls + '">' + item.status + '</span></td></tr>';
                    });
                    $panel.find('.df-acc-items-table tbody').html(rows.join(''));
                    $panel.find('.df-acc-items-table').show();
                    if (!detailCache[apiId]) detailCache[apiId] = {};
                    detailCache[apiId].jsonLoaded = false;
                });
            }

            /* ── Load raw JSON (lazy) ─────────────────────────────── */
            function loadJson($acc, apiId) {
                var $panel = $acc.find('[data-panel="json"]');
                $panel.find('.df-acc-json-loading').show();
                $panel.find('.df-acc-json-code').hide();

                $.post(ajaxUrl, { action: 'dataflair_toplist_raw_json', _ajax_nonce: nonces.rawJson, api_toplist_id: apiId }, function (res) {
                    $panel.find('.df-acc-json-loading').hide();
                    if (!res.success) { $panel.find('.df-acc-json-loading').text('Failed to load JSON.').show(); return; }
                    $panel.find('.df-acc-json-code').text((res.data || {}).json || '').show();
                    if (detailCache[apiId]) detailCache[apiId].jsonLoaded = true;
                });
            }

            /* ── Copy JSON ───────────────────────────────────────── */
            $(document).on('click', '.df-json-copy', function () {
                var $btn  = $(this);
                var text  = $btn.closest('.df-acc-panel').find('.df-acc-json-code').text();
                navigator.clipboard && navigator.clipboard.writeText(text).then(function () {
                    $btn.text('Copied!');
                    setTimeout(function () { $btn.text('Copy'); }, 2000);
                });
            });

            /* ── Download JSON link ───────────────────────────────── */
            $(document).on('click', '.df-json-download', function (e) {
                e.preventDefault();
                var apiId = $(this).data('api-id');
                window.location.href = ajaxUrl + '?action=dataflair_toplist_raw_json&_ajax_nonce=' +
                    encodeURIComponent(nonces.rawJson) + '&api_toplist_id=' + apiId + '&format=download';
            });

            /* ── Per-row Re-sync ─────────────────────────────────── */
            $(document).on('click', '.df-acc-resync', function () {
                var $btn = $(this);
                $btn.prop('disabled', true).text('Syncing…');
                startToplistBatchSync($btn, function () { $btn.prop('disabled', false).text('↻ Re-sync'); });
            });

            /* ── Select all / Bulk bar ───────────────────────────── */
            var selectedIds = {};
            $('#df-toplist-select-all').on('change', function () {
                var checked = this.checked;
                $('.df-toplist-check:visible').each(function () {
                    this.checked = checked;
                    var id = parseInt($(this).val());
                    if (checked) selectedIds[id] = true; else delete selectedIds[id];
                });
                syncBulkBar();
            });
            $(document).on('change', '.df-toplist-check', function () {
                var id = parseInt($(this).val());
                if (this.checked) selectedIds[id] = true; else delete selectedIds[id];
                syncBulkBar();
            });
            function syncBulkBar() {
                var n = Object.keys(selectedIds).length;
                if (n > 0) {
                    $('#df-toplist-selected-count').text(n + ' selected');
                    $('#df-toplist-bulk-bar').css('display', 'flex');
                } else {
                    $('#df-toplist-bulk-bar').hide();
                }
            }

            /* ── Bulk resync ─────────────────────────────────────── */
            $('#df-toplist-bulk-resync').on('click', function () {
                var ids = Object.keys(selectedIds).map(Number);
                if (!ids.length) return;
                $(this).prop('disabled', true).text('Starting…');
                var self = this;
                $.post(ajaxUrl, { action: 'dataflair_bulk_resync_toplists', _ajax_nonce: nonces.bulkResync, api_toplist_ids: ids }, function (res) {
                    $(self).prop('disabled', false).text('Re-sync Selected');
                    if (res.success) {
                        var d = res.data || {};
                        alert('Re-sync complete: ' + (d.message || (d.synced + ' synced')));
                    } else {
                        alert((res.data || {}).message || 'Error starting resync.');
                    }
                });
            });

            /* ── Bulk delete ─────────────────────────────────────── */
            $('#df-toplist-bulk-delete').on('click', function () {
                var ids = Object.keys(selectedIds).map(Number);
                if (!ids.length) return;
                if (!confirm('Delete ' + ids.length + ' toplist(s)? This cannot be undone.')) return;
                var $btn = $(this);
                $btn.prop('disabled', true).text('Deleting…');
                $.post(ajaxUrl, { action: 'dataflair_bulk_delete_toplists', _ajax_nonce: nonces.bulkDelete, api_toplist_ids: ids }, function (res) {
                    $btn.prop('disabled', false).text('Delete Selected');
                    if (res.success) { window.location.reload(); }
                    else { alert((res.data || {}).message || 'Delete failed.'); }
                });
            });

            /* ── Fetch All Toplists + Sync Console ───────────────── */
            var $tlConsole   = $('#df-tl-sync-console');
            var $tlBar       = $('#df-tl-progress-bar');
            var $tlStats     = $('#df-tl-stats');
            var $tlLog       = $('#df-tl-log');
            var $tlEta       = $('#df-tl-eta');

            function tlLog(msg, type) {
                var colors = { success:'#4ec94e', error:'#f4736a', info:'#7ecfff', done:'#f0e68c', muted:'#888' };
                var icons  = { success:'✓', error:'✗', info:'→', done:'★', muted:'-' };
                var now = new Date();
                var ts = now.toTimeString().slice(0,8);
                var c  = colors[type] || colors.info;
                var ic = icons[type]  || icons.info;
                $tlLog.append('<div style="color:' + c + ';margin-bottom:2px;">' + ts + ' <span>' + ic + '</span> ' + $('<span>').text(msg).html() + '</div>');
                $tlLog.scrollTop($tlLog[0].scrollHeight);
            }
            function tlProgress(page, total) {
                if (!total) return;
                var pct = Math.min(100, Math.round((page / total) * 100));
                $tlBar.css('width', pct + '%');
            }
            function tlFmtEta(ms) {
                if (ms < 60000) return Math.round(ms / 1000) + 's remaining';
                return Math.round(ms / 60000) + 'min remaining';
            }

            function startToplistBatchSync($triggerBtn, onDone) {
                $tlConsole.show();
                $tlLog.empty();
                $tlBar.css('width', '0');
                $tlStats.text('Validating token…');
                $tlEta.text('');
                tlLog('Validating API token…', 'info');

                $.post(ajaxUrl, { action: 'dataflair_fetch_all_toplists', _ajax_nonce: nonces.fetchAll }, function (res) {
                    if (!res || !res.success) {
                        var errMsg = (res && res.data && res.data.message) ? res.data.message : 'Token validation failed';
                        tlLog('Error: ' + errMsg, 'error');
                        $tlStats.text('Failed — ' + errMsg);
                        if ($triggerBtn) $triggerBtn.prop('disabled', false).text('Fetch All Toplists from API');
                        if (onDone) onDone(false);
                        return;
                    }
                    tlLog('Token OK — starting page sync…', 'info');

                    var page = 1, totalPages = null, syncedTotal = 0, tStart = Date.now();

                    function nextPage() {
                        var tPage = Date.now();
                        $.post(ajaxUrl, { action: 'dataflair_sync_toplists_batch', _ajax_nonce: nonces.syncBatch, page: page }, function (r) {
                            var pageMs     = Date.now() - tPage;
                            var data       = (r && r.data) ? r.data : {};

                            if (!r || !r.success) {
                                var eMsg = data.message || 'Server error on page ' + page;
                                tlLog('Error on page ' + page + ': ' + eMsg, 'error');
                                $tlStats.text('Stopped — ' + eMsg);
                                if ($triggerBtn) $triggerBtn.prop('disabled', false).text('Fetch All Toplists from API');
                                if (onDone) onDone(false);
                                return;
                            }

                            var synced     = data.synced     || 0;
                            var errors     = data.errors     || 0;
                            var isComplete = data.is_complete || false;
                            var lastPage   = data.last_page  || page;
                            var nextPageNo = data.next_page  || (page + 1);
                            syncedTotal   += synced;

                            if (totalPages === null) {
                                totalPages = lastPage;
                                tlLog('API reports ' + totalPages + ' page(s) to sync', 'info');
                                $tlStats.text('Page 1 of ' + totalPages + ' · 0 synced');
                            }

                            tlProgress(page, totalPages);
                            var lineType = errors > 0 ? 'error' : 'success';
                            var lineMsg  = 'Page ' + page + '/' + totalPages + '  ·  +' + synced + ' synced';
                            if (errors > 0) lineMsg += '  ·  ⚠ ' + errors + ' errors';
                            if (synced === 0 && errors === 0) lineMsg += '  ·  (skipped)';
                            lineMsg += '  ·  ' + pageMs + 'ms';
                            tlLog(lineMsg, lineType);

                            var elapsed = Date.now() - tStart;
                            var avg     = elapsed / page;
                            $tlStats.text('Page ' + page + ' of ' + totalPages + ' · ' + syncedTotal + ' synced');
                            $tlEta.text(!isComplete ? tlFmtEta((totalPages - page) * avg) : '');

                            if (!isComplete) {
                                page = nextPageNo;
                                nextPage();
                            } else {
                                var totalSec = ((Date.now() - tStart) / 1000).toFixed(1);
                                tlLog('Done — ' + syncedTotal + ' toplists synced in ' + totalSec + 's', 'done');
                                $tlStats.text(syncedTotal + ' toplists synced in ' + totalSec + 's');
                                $tlEta.text('');
                                if ($triggerBtn) $triggerBtn.prop('disabled', false).text('Fetch All Toplists from API ✓');
                                if (onDone) onDone(true);
                            }
                        }).fail(function () {
                            tlLog('Page ' + page + ' request failed (network error)', 'error');
                            $tlStats.text('Network error on page ' + page);
                            if ($triggerBtn) $triggerBtn.prop('disabled', false).text('Fetch All Toplists from API');
                            if (onDone) onDone(false);
                        });
                    }
                    nextPage();
                }).fail(function () {
                    tlLog('Network error during token validation', 'error');
                    $tlStats.text('Network error.');
                    if ($triggerBtn) $triggerBtn.prop('disabled', false).text('Fetch All Toplists from API');
                    if (onDone) onDone(false);
                });
            }

            $('#dataflair-fetch-all-toplists').on('click', function () {
                var $btn = $(this).prop('disabled', true).text('Syncing…');
                startToplistBatchSync($btn, null);
            });

            /* ── Search + filter ─────────────────────────────────── */
            function filterRows() {
                var q        = $('#df-toplist-search').val().toLowerCase();
                var template = $('#dataflair-filter-template').val();
                var shown = 0;
                $('.toplist-row').each(function () {
                    var $row = $(this);
                    var matchSearch   = !q || ($row.data('name') + ' ' + $row.data('slug')).includes(q);
                    var matchTemplate = !template || $row.data('template') === template;
                    var visible = matchSearch && matchTemplate;
                    $row.toggle(visible);
                    // Keep accordion row in sync
                    var accId = 'acc-' + $row.data('toplist-id');
                    if (!visible) $('#' + accId).hide();
                    if (visible) shown++;
                });
                $('#dataflair-toplists-count').text('Showing ' + shown + ' toplists');
            }
            $('#df-toplist-search').on('input', filterRows);
            $('#dataflair-filter-template').on('change', filterRows);
            $('#dataflair-clear-toplist-filters').on('click', function () {
                $('#df-toplist-search').val('');
                $('#dataflair-filter-template').val('');
                filterRows();
            });

            /* ── Sort ────────────────────────────────────────────── */
            var sortCol = '', sortDir = 'asc';
            $(document).on('click', '.toplist-sort-link', function (e) {
                e.preventDefault();
                var col = $(this).data('sort');
                sortDir = (sortCol === col && sortDir === 'asc') ? 'desc' : 'asc';
                sortCol = col;
                $('.toplist-sort-indicator').text('');
                $(this).find('.toplist-sort-indicator').text(sortDir === 'asc' ? ' ▲' : ' ▼');
                var $tbody = $('.dataflair-toplists-table tbody');
                var $rows  = $tbody.find('.toplist-row').toArray();
                $rows.sort(function (a, b) {
                    var av = sortCol === 'template' ? $(a).data('template') :
                             sortCol === 'items'    ? parseInt($(a).data('items')) :
                                                      $(a).data('last-synced');
                    var bv = sortCol === 'template' ? $(b).data('template') :
                             sortCol === 'items'    ? parseInt($(b).data('items')) :
                                                      $(b).data('last-synced');
                    if (av < bv) return sortDir === 'asc' ? -1 : 1;
                    if (av > bv) return sortDir === 'asc' ?  1 : -1;
                    return 0;
                });
                $rows.forEach(function (row) {
                    var id   = $(row).data('toplist-id');
                    var $acc = $('#acc-' + id);
                    $tbody.append(row);
                    if ($acc.length) $tbody.append($acc[0]);
                });
            });

            function esc(s) {
                return $('<span>').text(s || '').html();
            }
        });
        </script>
        <style>
            .toplist-toggle-btn { background:none;border:none;cursor:pointer;padding:4px;display:flex;align-items:center;transition:all 0.2s; }
            .toplist-toggle-btn:hover { background:#f0f0f1;border-radius:3px; }
            .toplist-toggle-btn .dashicons { transition:transform 0.2s; }
            .toplist-accordion-inner { padding:16px 20px;background:#f9f9f9;border-left:4px solid #0073aa;animation:slideIn 0.2s ease-out; }
            @keyframes slideIn { from{opacity:0;transform:translateY(-8px)} to{opacity:1;transform:translateY(0)} }
            .df-accordion-tabs { display:flex;align-items:center;gap:8px;margin-bottom:12px;border-bottom:1px solid #ddd;padding-bottom:8px; }
            .df-acc-tab { background:none;border:none;cursor:pointer;padding:4px 10px;border-radius:3px;font-weight:600;color:#1d2327; }
            .df-acc-tab:hover { background:#e8f0fe; }
            .df-acc-tab--active { background:#2271b1;color:#fff; }
            .df-acc-meta { color:#646970;font-size:12px; }
            .df-acc-items-table { width:100%;border-collapse:collapse; }
            .df-acc-items-table th,.df-acc-items-table td { padding:6px 10px;text-align:left;border-bottom:1px solid #eee; }
            .df-acc-items-table th { background:#f0f0f1;font-weight:600; }
            .df-acc-json-toolbar { display:flex;gap:8px;margin-bottom:8px; }
            .df-acc-json-code { background:#1e1e1e;color:#d4d4d4;padding:12px;border-radius:4px;max-height:400px;overflow:auto;font-size:12px;white-space:pre;font-family:monospace; }
            .sortable-toplist { padding:0; }
            .toplist-sort-link { display:flex;align-items:center;justify-content:space-between;padding:8px 10px;text-decoration:none;color:inherit;white-space:nowrap; }
            .toplist-sort-link:hover { background:#f0f0f1; }
        </style>
        <?php
    }
}
