/**
 * DataFlair — Shared Sync Console widget.
 *
 * Creates a window.DFSyncConsole constructor. Instantiate once per page with
 * the page-specific AJAX actions and nonces; call .start() when the trigger
 * button is clicked.
 *
 * Requires: jQuery, .df-sync-console HTML block (see PHP helpers), admin-ui.css
 *
 * cfg keys:
 *   consoleId   — id of the .df-sync-console container div
 *   btnId       — id of the trigger button
 *   btnLabel    — original button label (restored after sync)
 *   titleLabel  — text shown in the console header while running
 *   ajaxUrl     — admin-ajax.php URL
 *   fetchAction — AJAX action for the pre-flight / token-validation call
 *   batchAction — AJAX action called once per page
 *   fetchNonce  — nonce value for fetchAction
 *   batchNonce  — nonce value for batchAction
 */
(function ($) {
    'use strict';

    window.DFSyncConsole = function (cfg) {
        var $con   = $('#' + cfg.consoleId);
        var $btn   = $('#' + cfg.btnId);
        var $spin  = $con.find('.df-sync-console__spinner');
        var $title = $con.find('.df-sync-console__title');
        var $eta   = $con.find('.df-sync-console__eta');
        var $fill  = $con.find('.df-sync-console__bar-fill');
        var $pct   = $con.find('.df-sync-console__pct');
        var $stats = $con.find('.df-sync-console__stats');
        var $log   = $con.find('.df-sync-log');
        var url    = cfg.ajaxUrl;

        function log(msg, type) {
            var ts    = new Date().toTimeString().slice(0, 8);
            var cls   = 'df-sync-log-line--' + (type || 'info');
            var icons = { success: '✓', error: '✗', info: '→', done: '★', muted: '-' };
            var icon  = icons[type] || icons.info;
            $log.prepend(
                '<div class="df-sync-log-line ' + cls + '">' +
                '<span class="df-sync-log__ts">' + ts + '</span>' +
                '<span class="df-sync-log__icon">' + icon + '</span>' +
                '<span class="df-sync-log__msg">' + $('<span>').text(msg).html() + '</span>' +
                '</div>'
            );
        }

        function setProgress(pct, done) {
            $fill.css('width', pct + '%').attr('data-done', done ? '1' : '0');
            $pct.text(pct + '%');
            $spin.attr('data-done', done ? '1' : '0');
        }

        function fmtEta(ms) {
            if (ms < 60000) { return '~' + Math.round(ms / 1000) + 's remaining'; }
            return '~' + Math.round(ms / 60000) + 'min remaining';
        }

        this.start = function (onDone) {
            $con.attr('data-active', '1');
            $log.empty();
            setProgress(0, false);
            $title.text(cfg.titleLabel || 'Syncing…');
            $stats.text('Starting…');
            $eta.text('');
            $btn.prop('disabled', true).text('Syncing…');

            log('Validating API token…', 'info');

            $.post(url, { action: cfg.fetchAction, _ajax_nonce: cfg.fetchNonce }, function (res) {
                if (!res || !res.success) {
                    var errMsg = (res && res.data && res.data.message) ? res.data.message : 'Token validation failed';
                    log('Error: ' + errMsg, 'error');
                    $stats.text('Failed — ' + errMsg);
                    $btn.prop('disabled', false).text(cfg.btnLabel);
                    if (onDone) { onDone(false); }
                    return;
                }
                log('Token OK — starting page sync…', 'info');

                var page        = 1;
                var totalPages  = null;
                var syncedTotal = 0;
                var tStart      = Date.now();

                function nextPage() {
                    var tPage = Date.now();
                    $.post(url, {
                        action:      cfg.batchAction,
                        _ajax_nonce: cfg.batchNonce,
                        page:        page,
                    }, function (r) {
                        var pageMs = Date.now() - tPage;
                        var d      = (r && r.data) ? r.data : {};

                        if (!r || !r.success) {
                            var eMsg = d.message || 'Server error on page ' + page;
                            log('Error on page ' + page + ': ' + eMsg, 'error');
                            $stats.text('Stopped — ' + eMsg);
                            $btn.prop('disabled', false).text(cfg.btnLabel);
                            if (onDone) { onDone(false); }
                            return;
                        }

                        // Normalise field names — toplists and brands handlers differ slightly
                        var synced     = d.synced || d.synced_count || 0;
                        var errors     = d.errors || 0;
                        var isComplete = ('is_complete' in d) ? d.is_complete : !d.has_more;
                        var nextPageNo = d.next_page || (page + 1);
                        syncedTotal   += synced;

                        if (totalPages === null) {
                            if (d.last_page) {
                                totalPages = d.last_page;
                            } else if (d.total_brands && synced > 0) {
                                totalPages = Math.ceil(d.total_brands / synced);
                            }
                            if (totalPages) {
                                log('API reports ' + totalPages + ' page(s) to sync', 'info');
                            }
                        }

                        var pct = totalPages ? Math.min(99, Math.round((page / totalPages) * 100)) : 0;
                        setProgress(pct, false);

                        var lineType = errors > 0 ? 'error' : 'success';
                        var lineMsg  = 'Page ' + page + (totalPages ? '/' + totalPages : '') + '  ·  +' + synced + ' synced';
                        if (errors > 0) { lineMsg += '  ·  ⚠ ' + errors + ' errors'; }
                        if (synced === 0 && errors === 0) { lineMsg += '  ·  (skipped)'; }
                        lineMsg += '  ·  ' + pageMs + 'ms';
                        log(lineMsg, lineType);

                        var elapsed = Date.now() - tStart;
                        var avg     = elapsed / page;
                        $stats.text('Page ' + page + (totalPages ? ' of ' + totalPages : '') + ' · ' + syncedTotal + ' synced');
                        $eta.text((!isComplete && totalPages) ? fmtEta((totalPages - page) * avg) : '');

                        if (!isComplete) {
                            page = nextPageNo;
                            nextPage();
                        } else {
                            var totalSec = ((Date.now() - tStart) / 1000).toFixed(1);
                            log('Done — ' + syncedTotal + ' synced in ' + totalSec + 's', 'done');
                            $stats.text(syncedTotal + ' synced in ' + totalSec + 's');
                            $eta.text('');
                            setProgress(100, true);
                            $btn.prop('disabled', false).text(cfg.btnLabel + ' ✓');
                            if (onDone) { onDone(true); }
                        }
                    }).fail(function () {
                        log('Page ' + page + ' request failed (network error)', 'error');
                        $stats.text('Network error on page ' + page);
                        $btn.prop('disabled', false).text(cfg.btnLabel);
                        if (onDone) { onDone(false); }
                    });
                }

                nextPage();

            }).fail(function () {
                log('Network error during token validation', 'error');
                $stats.text('Network error.');
                $btn.prop('disabled', false).text(cfg.btnLabel);
                if (onDone) { onDone(false); }
            });
        };
    };

}(jQuery));
