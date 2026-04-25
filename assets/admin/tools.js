/* DataFlair Admin — Tools page (tests + logs). Phase 9.6. */
(function ($) {
    'use strict';

    var cfg = window.DFTools || {};
    var ajaxUrl = cfg.ajaxUrl || '';
    var nonces  = cfg.nonces  || {};

    /* ── Helpers ─────────────────────────────────────────────────── */

    function pill(status) {
        var cls = { pass: 'success', fail: 'error', warn: 'warning', pending: 'gray' };
        var label = { pass: 'Pass', fail: 'Fail', warn: 'Warn', pending: 'Pending' };
        return '<span class="df-pill df-pill--' + (cls[status] || 'gray') + '">' +
            (label[status] || status) + '</span>';
    }

    function post(action, data, nonce) {
        data._ajax_nonce = nonce;
        data.action      = action;
        return $.post(ajaxUrl, data);
    }

    /* ── Update a single test row ─────────────────────────────────── */

    function applyResult(slug, r) {
        var $row = $('[data-slug="' + slug + '"]');
        if (!$row.length) { return; }
        $row.find('.df-test-status').html(pill(r.status));
        $row.find('.df-test-message').text(r.message || '');
        var ts = r.last_run_iso ? new Date(r.last_run_iso).toLocaleString() : '';
        $row.find('.df-test-ts').text(ts);
        $row.find('.df-test-duration').text(r.duration_ms != null ? r.duration_ms + ' ms' : '');
    }

    function updateSummary(summary) {
        var $bar = $('#df-tests-statusbar');
        if (!$bar.length || !summary) { return; }
        $bar.find('.df-stat-pass').text(summary.pass + ' pass');
        $bar.find('.df-stat-fail')
            .text(summary.fail + ' failing')
            .toggleClass('df-text-danger', summary.fail > 0);
        $bar.find('.df-stat-warn').text(summary.warn + ' warn');
    }

    /* ── Per-row Run button ──────────────────────────────────────── */

    $(document).on('click', '.df-run-test', function () {
        var $btn  = $(this);
        var slug  = $btn.data('slug');
        $btn.prop('disabled', true).text('Running…');

        post('dataflair_run_test', { slug: slug }, nonces.runTest)
            .done(function (res) {
                if (res.success) {
                    applyResult(slug, res.data);
                }
            })
            .always(function () {
                $btn.prop('disabled', false).text('Run');
            });
    });

    /* ── Run All ────────────────────────────────────────────────── */

    $('#df-run-all-btn').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Running all…');

        post('dataflair_run_all_tests', {}, nonces.runAll)
            .done(function (res) {
                if (res.success && res.data) {
                    $.each(res.data.results || {}, function (slug, r) {
                        applyResult(slug, r);
                    });
                    updateSummary(res.data.summary);
                }
            })
            .always(function () {
                $btn.prop('disabled', false).text('▶ Run all');
            });
    });

    /* ── Logs tab ───────────────────────────────────────────────── */

    var LEVEL_CLASS = {
        error:   'df-log--error',
        warning: 'df-log--warning',
        warn:    'df-log--warning',
        info:    'df-log--info',
        debug:   'df-log--debug',
    };

    function renderLogEntry(e) {
        var cls  = LEVEL_CLASS[e.level] || 'df-log--info';
        var ts   = e.ts ? '<span class="df-log-ts">' + e.ts + '</span> ' : '';
        var lvl  = '<span class="df-log-level df-log-level--' + (e.level || 'info') + '">' +
                   (e.level || 'info').toUpperCase() + '</span>';
        var msg  = $('<span>').text(e.message).html(); // escape
        return '<div class="df-log-entry ' + cls + '">' + ts + lvl + ' ' + msg + '</div>';
    }

    function loadLogs() {
        var $btn       = $('#df-logs-refresh');
        var $container = $('#df-logs-container');

        $btn.prop('disabled', true).text('Loading…');
        $container.html('<div class="df-log-entry df-log--info">Fetching…</div>');

        post('dataflair_logs_tail', {}, nonces.logsTail)
            .done(function (res) {
                if (!res.success) {
                    $container.html('<div class="df-log-entry df-log--error">Request failed.</div>');
                    return;
                }
                var d = res.data || {};
                if (d.notice) {
                    $container.html('<div class="df-log-entry df-log--info">' + $('<span>').text(d.notice).html() + '</div>');
                    return;
                }
                if (!d.entries || !d.entries.length) {
                    $container.html('<div class="df-log-entry df-log--info">No DataFlair log entries found.</div>');
                    return;
                }
                var html = d.entries.map(renderLogEntry).join('');
                if (d.truncated) {
                    html = '<div class="df-log-entry df-log--info">Showing last 200 of ' +
                        d.total + ' entries.</div>' + html;
                }
                $container.html(html);
            })
            .fail(function () {
                $container.html('<div class="df-log-entry df-log--error">AJAX error — check server.</div>');
            })
            .always(function () {
                $btn.prop('disabled', false).text('⟳ Load Logs');
            });
    }

    $('#df-logs-refresh').on('click', loadLogs);

    /* Download link — build nonce-signed URL on click so nonce is always fresh */
    $('#df-logs-download').on('click', function (e) {
        e.preventDefault();
        var url = ajaxUrl + '?action=dataflair_logs_download&_ajax_nonce=' +
            encodeURIComponent(nonces.logsDownload);
        window.location.href = url;
    });

}(jQuery));
