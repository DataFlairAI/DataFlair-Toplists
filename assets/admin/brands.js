/**
 * Phase 9.6 (admin UX redesign) — Brands list page controller.
 *
 * Manages: search/filter state → BrandsQueryHandler AJAX, checkbox
 * selection + bulk bar, sort indicators, pagination, inline review-URL
 * edit, and the Sync Brands batch flow.
 *
 * Requires: window.DFBrands (set inline by BrandsPage::renderInlineScript)
 *           window.DFAdmin  (set by admin-ui.js)
 */
(function ($) {
    'use strict';

    if (typeof window.DFBrands === 'undefined') { return; }

    var cfg   = window.DFBrands;
    var state = Object.assign({}, cfg.state);          // mutable query state
    var selectedIds = {};                               // api_brand_id → true

    // ── Query ──────────────────────────────────────────────────────────────

    function doQuery(opts) {
        opts = opts || {};
        var payload = Object.assign({
            action:      'dataflair_brands_query',
            _ajax_nonce: cfg.nonces.query,
        }, state);

        // Serialize array values for AJAX
        if (state.licenses.length)      payload.licenses      = state.licenses;
        if (state.geos.length)          payload.geos           = state.geos;
        if (state.payments.length)      payload.payments       = state.payments;
        if (state.product_types.length) payload.product_types  = state.product_types;

        $('#df-brands-count-label').text('Loading…');
        $('#df-brands-tbody').css('opacity', '0.5');

        $.post(cfg.ajaxUrl, payload, function (res) {
            $('#df-brands-tbody').css('opacity', '1');
            if (!res.success) {
                DFAdmin.toast('error', res.data && res.data.message ? res.data.message : 'Query failed.');
                return;
            }
            var d = res.data;
            state.page   = d.page;
            state.pages  = d.pages;
            state.total  = d.total;

            renderTbody(d.rows);
            renderPagination(d.page, d.pages, d.total);
            $('#df-brands-count-label').text(numberFmt(d.total) + ' brands');
            updateClearFiltersLink();
            if (!opts.silentSelect) { selectedIds = {}; syncBulkBar(); }
        }).fail(function () {
            $('#df-brands-tbody').css('opacity', '1');
            DFAdmin.toast('error', 'Network error — could not load brands.');
        });
    }

    // ── Render helpers ─────────────────────────────────────────────────────

    function renderTbody(rows) {
        var html = '';
        if (!rows || rows.length === 0) {
            html = '<tr><td colspan="8" style="text-align:center;padding:24px;color:#646970;">'
                 + 'No brands found. Try adjusting your filters.</td></tr>';
        } else {
            rows.forEach(function (b) { html += buildRow(b); });
        }
        $('#df-brands-tbody').html(html);
    }

    function buildRow(b) {
        var apiId       = parseInt(b.api_brand_id || 0, 10);
        var name        = esc(b.name        || '');
        var slug        = esc(b.slug        || '');
        var productType = esc(b.product_types || '');
        var offers      = parseInt(b.offers_count   || 0, 10);
        var trackers    = parseInt(b.trackers_count || 0, 10);
        var lastSynced  = b.last_synced ? esc(b.last_synced.substr(0, 10)) : '—';
        var isDisabled  = parseInt(b.is_disabled || 0, 10) === 1;
        var reviewUrl   = esc(b.review_url_override || '');
        var logo        = esc(b.local_logo_url || '');
        var initials    = (b.name || '').substr(0, 2).toUpperCase();
        var incomplete  = !b.product_types;
        var checked     = selectedIds[apiId] ? ' checked' : '';
        var rowClass    = 'df-brand-row' + (isDisabled ? ' df-brand-row--disabled' : '');

        var logoHtml = logo
            ? '<img src="' + logo + '" alt="" class="brand-logo-thumb"'
              + ' style="width:32px;height:32px;object-fit:contain;border-radius:3px;"'
              + ' onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'flex\'">'
              + '<div class="df-brand-initials"'
              + ' style="display:none;width:32px;height:32px;background:#e0e0e0;border-radius:3px;'
              + 'align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#444;">'
              + esc(initials) + '</div>'
            : '<div class="df-brand-initials"'
              + ' style="width:32px;height:32px;background:#e0e0e0;border-radius:3px;display:flex;'
              + 'align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#444;">'
              + esc(initials) + '</div>';

        var incPill = incomplete
            ? '<span class="df-pill df-pill--warning" style="font-size:10px;margin-left:6px;">incomplete metadata</span>'
            : '';

        var typePill = productType
            ? '<span class="df-pill df-pill--info">' + productType + '</span>'
            : '<span style="color:#aaa;">—</span>';

        var statusPill = isDisabled
            ? '<span class="df-pill df-pill--gray">Disabled</span>'
            : '<span class="df-pill df-pill--success">Active</span>';

        var reviewDisplay = reviewUrl
            ? '<span class="df-inline-display">' + reviewUrl + '</span>'
            : '<span class="df-inline-display" style="color:#aaa;"><em>not set</em></span>';

        return '<tr class="' + rowClass + '" data-brand-id="' + apiId + '">'
            + '<td class="check-column"><input type="checkbox" class="df-brand-check" value="' + apiId + '"' + checked + '></td>'
            + '<td class="df-brand-identity-cell">'
            +   '<div style="display:flex;align-items:center;gap:10px;">'
            +     '<div class="df-brand-logo-wrap" style="flex-shrink:0;">' + logoHtml + '</div>'
            +     '<div>'
            +       '<strong>' + name + '</strong>' + incPill + '<br>'
            +       '<span style="color:#646970;font-size:12px;font-family:monospace;">' + slug
            +       ' <span style="color:#aaa;">#' + apiId + '</span></span>'
            +     '</div>'
            +   '</div>'
            + '</td>'
            + '<td>' + typePill + '</td>'
            + '<td style="text-align:center;">' + offers + '</td>'
            + '<td style="text-align:center;">' + trackers + '</td>'
            + '<td>' + lastSynced + '</td>'
            + '<td>' + statusPill + '</td>'
            + '<td class="df-review-url-cell" style="min-width:200px;">'
            +   reviewDisplay
            +   '<input type="text" class="df-inline-input df-brand-review-url"'
            +     ' data-brand-id="' + apiId + '" value="' + reviewUrl + '"'
            +     ' placeholder="/reviews/' + (b.slug || 'brand-slug') + '/"'
            +     ' style="display:none;width:100%;margin-top:4px;">'
            +   '<div style="margin-top:4px;display:flex;gap:4px;">'
            +     '<button type="button" class="button button-small df-inline-edit-btn" data-brand-id="' + apiId + '">Edit</button>'
            +     '<button type="button" class="button button-small button-primary df-inline-save-btn" data-brand-id="' + apiId + '" style="display:none;">Save</button>'
            +     '<button type="button" class="button button-small df-inline-cancel-btn" data-brand-id="' + apiId + '" style="display:none;">Cancel</button>'
            +   '</div>'
            + '</td>'
            + '</tr>';
    }

    function renderPagination(page, pages, total) {
        var prev = Math.max(1, page - 1);
        var next = Math.min(pages, page + 1);
        var html =
            '<div class="tablenav-pages" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">'
          + '<span id="df-brands-paging-label" class="displaying-num">'
          +   numberFmt(total) + ' brands — page ' + page + ' of ' + pages
          + '</span>'
          + '<span class="pagination-links">'
          + '<button type="button" class="button df-page-btn" data-page="1"' + (page <= 1 ? ' disabled' : '') + '>«</button>'
          + '<button type="button" class="button df-page-btn" data-page="' + prev + '"' + (page <= 1 ? ' disabled' : '') + '>‹</button>'
          + '<span style="padding:0 8px;">Page ' + page + ' of ' + pages + '</span>'
          + '<button type="button" class="button df-page-btn" data-page="' + next + '"' + (page >= pages ? ' disabled' : '') + '>›</button>'
          + '<button type="button" class="button df-page-btn" data-page="' + pages + '"' + (page >= pages ? ' disabled' : '') + '>»</button>'
          + '</span></div>';
        $('#df-brands-pagination').html(html);
    }

    // ── Sort headers ────────────────────────────────────────────────────────

    $(document).on('click', '.df-sort-link', function (e) {
        e.preventDefault();
        var col = $(this).data('sort');
        if (state.sort_by === col) {
            state.sort_dir = state.sort_dir === 'ASC' ? 'DESC' : 'ASC';
        } else {
            state.sort_by  = col;
            state.sort_dir = 'ASC';
        }
        state.page = 1;
        updateSortIndicators();
        doQuery();
    });

    function updateSortIndicators() {
        $('.df-sort-link').each(function () {
            var col = $(this).data('sort');
            var ind = $(this).find('.df-sort-indicator');
            if (col === state.sort_by) {
                ind.text(state.sort_dir === 'ASC' ? ' ▲' : ' ▼');
            } else {
                ind.text('');
            }
        });
    }

    // ── Search ──────────────────────────────────────────────────────────────

    var searchTimer;
    $(document).on('input', '#df-brands-search', function () {
        var val = $(this).val();
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () {
            state.search = val;
            state.page   = 1;
            doQuery();
        }, 200);
    });

    // ── Filter chips ────────────────────────────────────────────────────────

    // Toggle dropdown open/close
    $(document).on('click', '.df-filter-chip__trigger', function (e) {
        e.stopPropagation();
        var $chip     = $(this).closest('.df-filter-chip');
        var $dropdown = $chip.find('.df-filter-chip__dropdown');
        var isOpen    = $dropdown.is(':visible');
        // Close all other chips first
        $('.df-filter-chip__dropdown').hide();
        $('.df-filter-chip__trigger').attr('aria-expanded', 'false');
        if (!isOpen) {
            $dropdown.show();
            $(this).attr('aria-expanded', 'true');
        }
    });

    // Close on outside click
    $(document).on('click', function (e) {
        if (!$(e.target).closest('.df-filter-chip').length) {
            $('.df-filter-chip__dropdown').hide();
            $('.df-filter-chip__trigger').attr('aria-expanded', 'false');
        }
    });

    // Option checkbox change → update state + query
    $(document).on('change', '.df-chip-option', function () {
        var $chip      = $(this).closest('.df-filter-chip');
        var filterKey  = $chip.data('filter');
        var vals       = [];
        $chip.find('.df-chip-option:checked').each(function () { vals.push($(this).val()); });

        state[filterKey] = vals;
        state.page = 1;

        var badge = $chip.find('.df-filter-chip__badge');
        if (vals.length > 0) {
            badge.text(vals.length).show();
        } else {
            badge.hide();
        }

        updateClearFiltersLink();
        doQuery();
    });

    function updateClearFiltersLink() {
        var hasFilters = state.search ||
            state.licenses.length || state.geos.length ||
            state.payments.length || state.product_types.length;
        $('#df-brands-clear-filters').toggle(!!hasFilters);
    }

    $(document).on('click', '#df-brands-clear-filters', function (e) {
        e.preventDefault();
        state.search        = '';
        state.licenses      = [];
        state.geos          = [];
        state.payments      = [];
        state.product_types = [];
        state.page          = 1;
        $('#df-brands-search').val('');
        $('.df-chip-option').prop('checked', false);
        $('.df-filter-chip__badge').hide();
        updateClearFiltersLink();
        doQuery();
    });

    // ── Pagination ──────────────────────────────────────────────────────────

    $(document).on('click', '.df-page-btn', function () {
        var pg = parseInt($(this).data('page'), 10);
        if (isNaN(pg) || $(this).is(':disabled')) { return; }
        state.page = pg;
        doQuery();
    });

    // ── Row selection + bulk bar ─────────────────────────────────────────────

    $(document).on('change', '#df-select-all', function () {
        var checked = this.checked;
        $('.df-brand-check').prop('checked', checked).each(function () {
            var id = parseInt(this.value, 10);
            if (checked) { selectedIds[id] = true; } else { delete selectedIds[id]; }
        });
        syncBulkBar();
    });

    $(document).on('change', '.df-brand-check', function () {
        var id = parseInt(this.value, 10);
        if (this.checked) { selectedIds[id] = true; } else { delete selectedIds[id]; }
        syncBulkBar();
    });

    function syncBulkBar() {
        var count = Object.keys(selectedIds).length;
        var $bar  = $('#df-bulk-bar');
        if (count > 0) {
            $bar.show();
            $('#df-bulk-count').text(count + ' selected');
        } else {
            $bar.hide();
        }
        // Sync select-all indeterminate state
        var total = $('.df-brand-check').length;
        var $sa = $('#df-select-all');
        $sa.prop('indeterminate', count > 0 && count < total);
        $sa.prop('checked', count > 0 && count === total);
    }

    $(document).on('click', '#df-bulk-deselect', function () {
        selectedIds = {};
        $('.df-brand-check, #df-select-all').prop('checked', false);
        syncBulkBar();
    });

    // Show/hide pattern input based on selected action
    $(document).on('change', '#df-bulk-action', function () {
        if ($(this).val() === 'apply_pattern') {
            $('#df-bulk-pattern').show().focus();
        } else {
            $('#df-bulk-pattern').hide();
        }
    });

    // Apply bulk action
    $(document).on('click', '#df-bulk-apply', function () {
        var action = $('#df-bulk-action').val();
        var ids    = Object.keys(selectedIds).map(Number);
        if (!action || ids.length === 0) { return; }

        if (action === 'apply_pattern') {
            var pattern = $('#df-bulk-pattern').val().trim();
            if (!pattern) { DFAdmin.toast('error', 'Enter a URL pattern first.'); return; }
            if (pattern.indexOf('{slug}') === -1) {
                DFAdmin.toast('error', 'Pattern must contain the {slug} token.'); return;
            }
            $.post(cfg.ajaxUrl, {
                action:          'dataflair_bulk_apply_review_pattern',
                _ajax_nonce:     cfg.nonces.applyPattern,
                api_brand_ids:   ids,
                pattern:         pattern,
            }, function (res) {
                if (res.success) {
                    DFAdmin.toast('success', 'Review URL applied to ' + res.data.updated + ' brand(s).');
                    doQuery({ silentSelect: true });
                } else {
                    DFAdmin.toast('error', res.data.message || 'Failed.');
                }
            }).fail(function () { DFAdmin.toast('error', 'Network error.'); });

        } else if (action === 'disable' || action === 'enable') {
            var disabled = action === 'disable';
            if (!DFAdmin.confirm((disabled ? 'Disable' : 'Enable') + ' ' + ids.length + ' brand(s)?')) { return; }
            $.post(cfg.ajaxUrl, {
                action:          'dataflair_bulk_disable_brands',
                _ajax_nonce:     cfg.nonces.disableBrands,
                api_brand_ids:   ids,
                disabled:        disabled ? 1 : 0,
            }, function (res) {
                if (res.success) {
                    var verb = disabled ? 'Disabled' : 'Enabled';
                    DFAdmin.toast('success', verb + ' ' + res.data.affected + ' brand(s).');
                    selectedIds = {};
                    doQuery();
                } else {
                    DFAdmin.toast('error', res.data.message || 'Failed.');
                }
            }).fail(function () { DFAdmin.toast('error', 'Network error.'); });

        } else if (action === 'resync') {
            $.post(cfg.ajaxUrl, {
                action:          'dataflair_bulk_resync_brands',
                _ajax_nonce:     cfg.nonces.resyncBrands,
                api_brand_ids:   ids,
            }, function (res) {
                if (res.success && res.data.start_batch) {
                    DFAdmin.toast('success', res.data.message || 'Sync started.');
                    startBrandsBatchSync();
                } else if (!res.success) {
                    DFAdmin.toast('error', res.data.message || 'Failed.');
                }
            }).fail(function () { DFAdmin.toast('error', 'Network error.'); });
        }
    });

    // ── Inline review-URL edit ──────────────────────────────────────────────

    $(document).on('click', '.df-inline-edit-btn', function () {
        var id   = $(this).data('brand-id');
        var $row = $(this).closest('.df-review-url-cell');
        $row.find('.df-inline-display').hide();
        $row.find('.df-inline-input').show().focus();
        $row.find('.df-inline-edit-btn').hide();
        $row.find('.df-inline-save-btn, .df-inline-cancel-btn').show();
    });

    $(document).on('click', '.df-inline-cancel-btn', function () {
        var $row = $(this).closest('.df-review-url-cell');
        $row.find('.df-inline-input').hide();
        $row.find('.df-inline-display').show();
        $row.find('.df-inline-save-btn, .df-inline-cancel-btn').hide();
        $row.find('.df-inline-edit-btn').show();
    });

    $(document).on('click', '.df-inline-save-btn', function () {
        var $btn = $(this);
        var id   = $btn.data('brand-id');
        var $row = $btn.closest('.df-review-url-cell');
        var url  = $row.find('.df-inline-input').val().trim();

        $btn.text('Saving…').prop('disabled', true);
        $.post(cfg.ajaxUrl, {
            action:      'dataflair_save_review_url',
            _ajax_nonce: cfg.nonces.saveReviewUrl,
            brand_id:    id,
            review_url:  url,
        }, function (res) {
            $btn.text('Save').prop('disabled', false);
            if (res.success) {
                var $disp = $row.find('.df-inline-display');
                if (url) {
                    $disp.css('color', '#000').html(escHtml(url));
                } else {
                    $disp.css('color', '#aaa').html('<em>not set</em>');
                }
                $row.find('.df-inline-input').hide().val(url);
                $row.find('.df-inline-display').show();
                $row.find('.df-inline-save-btn, .df-inline-cancel-btn').hide();
                $row.find('.df-inline-edit-btn').show();
                DFAdmin.toast('success', 'Review URL saved.');
            } else {
                DFAdmin.toast('error', 'Save failed.');
            }
        }).fail(function () {
            $btn.text('Save').prop('disabled', false);
            DFAdmin.toast('error', 'Network error.');
        });
    });

    // ── Sync Brands batch flow ───────────────────────────────────────────────

    var brandsConsole = new window.DFSyncConsole({
        consoleId:   'df-brands-sync-console',
        btnId:       'dataflair-fetch-all-brands',
        btnLabel:    'Sync Brands from API',
        titleLabel:  'Syncing Brands…',
        ajaxUrl:     cfg.ajaxUrl,
        fetchAction: 'dataflair_fetch_all_brands',
        batchAction: 'dataflair_sync_brands_batch',
        fetchNonce:  cfg.nonces.fetchBrands,
        batchNonce:  cfg.nonces.syncBrandsBatch,
    });

    $(document).on('click', '#dataflair-fetch-all-brands', function () {
        brandsConsole.start(function (success) {
            if (success) {
                DFAdmin.toast('success', 'Brands synced successfully.');
                doQuery();
            } else {
                DFAdmin.toast('error', 'Brands sync failed.');
            }
        });
    });

    // ── Utilities ────────────────────────────────────────────────────────────

    function esc(str) {
        return escHtml(str);
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function numberFmt(n) {
        return parseInt(n, 10).toLocaleString();
    }

})(jQuery);
