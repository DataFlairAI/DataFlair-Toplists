<?php
/**
 * Phase 9.6 (admin UX redesign) — Dashboard page.
 *
 * Displays: 4 stat tiles (Brands, Toplists, Last Sync, API Health),
 * Recent sync activity card (last 5 from dataflair_sync_history),
 * Scheduled jobs card with shortcode usage block.
 * Header actions: "Sync Brands" + "Sync Toplists" buttons.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Pages;

final class DashboardPage implements PageInterface
{
    public function render(): void
    {
        global $wpdb;

        $brands_table   = $wpdb->prefix . DATAFLAIR_BRANDS_TABLE_NAME;
        $toplists_table = $wpdb->prefix . DATAFLAIR_TABLE_NAME;

        $brand_count   = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$brands_table}");
        $toplist_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$toplists_table}");

        $last_brands_sync   = (int) get_option('dataflair_last_brands_sync', 0);
        $last_toplists_sync = (int) get_option('dataflair_last_toplists_sync', 0);
        $last_sync_ts       = max($last_brands_sync, $last_toplists_sync);

        // Prefer the throttling transient (most recent ping); fall back to the
        // persisted last-known result so the tile keeps showing the previous
        // status with "checked X ago" instead of resetting to Unknown after
        // the 60s transient expires.
        $api_health = get_transient('dataflair_api_health');
        if (!is_array($api_health)) {
            $api_health = get_option('dataflair_api_health_last', null);
        }
        if (!is_array($api_health)) {
            $api_health = ['status' => 'unknown', 'ping_ms' => 0, 'error' => '', 'checked_at' => 0];
        }

        $history = get_option('dataflair_sync_history', []);
        if (!is_array($history)) {
            $history = [];
        }
        $recent = array_slice($history, 0, 5);

        $usage_cache = get_transient('dataflair_toplist_usage');
        $usage_count = is_array($usage_cache) ? (int) ($usage_cache['count'] ?? 0) : null;
        ?>
        <div class="wrap">
            <div class="df-page-header">
                <h1 class="df-page-header__title">Dashboard</h1>
                <div class="df-page-header__actions">
                    <button type="button" id="df-dash-sync-brands" class="button">Sync Brands</button>
                    <button type="button" id="df-dash-sync-toplists" class="button button-primary">Sync Toplists</button>
                </div>
            </div>

            <?php $this->renderSyncConsole(); ?>

            <!-- Stat tiles -->
            <div class="df-stat-tiles">
                <?php $this->renderStatTile(
                    'Brands Synced',
                    number_format($brand_count),
                    $brand_count > 0 ? 'pass' : 'warn',
                    '?page=dataflair-brands'
                ); ?>
                <?php $this->renderStatTile(
                    'Toplists',
                    number_format($toplist_count),
                    $toplist_count > 0 ? 'pass' : 'warn',
                    '?page=dataflair-toplists-list'
                ); ?>
                <?php $this->renderLastSyncTile($last_sync_ts); ?>
                <?php $this->renderApiHealthTile($api_health); ?>
            </div>

            <!-- Bottom row: Recent activity + Scheduled jobs -->
            <div class="df-dashboard-grid">
                <div class="df-card df-card--wide">
                    <div class="df-card__header">
                        <h2 class="df-card__title">Recent Sync Activity</h2>
                        <a href="?page=dataflair-tools&tab=logs" class="df-card__link">View all →</a>
                    </div>
                    <?php if (empty($recent)): ?>
                        <p class="df-empty-state">No sync activity recorded yet. Run a sync to see history here.</p>
                    <?php else: ?>
                        <ul class="df-activity-list">
                            <?php foreach ($recent as $entry):
                                $ts    = isset($entry['ts']) ? (int) $entry['ts'] : 0;
                                $rel   = $ts > 0 ? human_time_diff($ts) . ' ago' : '—';
                                $stcls = $entry['status'] === 'success' ? 'pass' : ($entry['status'] === 'error' ? 'error' : 'warning');
                            ?>
                            <li class="df-activity-item">
                                <span class="df-pill df-pill--<?php echo esc_attr($stcls); ?>"><?php echo esc_html($entry['status'] ?? ''); ?></span>
                                <span class="df-activity-title"><?php echo esc_html($entry['title'] ?? ''); ?></span>
                                <span class="df-activity-ts"><?php echo esc_html($rel); ?></span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <div class="df-card df-card--narrow">
                    <div class="df-card__header">
                        <h2 class="df-card__title">WP-CLI Sync</h2>
                    </div>
                    <ul class="df-jobs-list">
                        <li>
                            <span class="df-jobs-label">Sync toplists</span>
                            <code style="font-size:11px;">wp dataflair sync toplists</code>
                        </li>
                        <li>
                            <span class="df-jobs-label">Sync brands</span>
                            <code style="font-size:11px;">wp dataflair sync brands</code>
                        </li>
                        <li>
                            <span class="df-jobs-label">Sync all</span>
                            <code style="font-size:11px;">wp dataflair sync all</code>
                        </li>
                        <li>
                            <span class="df-jobs-label">API health</span>
                            <code style="font-size:11px;">wp dataflair health</code>
                        </li>
                    </ul>

                    <div class="df-card__sub">
                        <h3 class="df-card__subtitle">Shortcode Usage</h3>
                        <?php if ($usage_count !== null): ?>
                            <p>Found on <strong><?php echo esc_html($usage_count); ?></strong> page(s).</p>
                        <?php else: ?>
                            <p class="df-text-muted">Not yet calculated.</p>
                        <?php endif; ?>
                        <code class="df-mono">[dataflair_toplist id="3" limit="5"]</code>
                        <button type="button" class="button button-small df-copy-shortcode" data-text='[dataflair_toplist id="3" limit="5"]'>Copy</button>
                    </div>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function ($) {
            var ajaxUrl            = <?php echo json_encode(admin_url('admin-ajax.php')); ?>;
            var fetchBrandsNonce   = <?php echo json_encode(wp_create_nonce('dataflair_fetch_all_brands')); ?>;
            var syncBrandsNonce    = <?php echo json_encode(wp_create_nonce('dataflair_sync_brands_batch')); ?>;
            var fetchToplistsNonce = <?php echo json_encode(wp_create_nonce('dataflair_fetch_all_toplists')); ?>;
            var syncToplistsNonce  = <?php echo json_encode(wp_create_nonce('dataflair_sync_toplists_batch')); ?>;
            var healthNonce        = <?php echo json_encode(wp_create_nonce('dataflair_api_health')); ?>;

            /* ── Sync console helpers ─────────────────────────── */
            var $console  = $('#df-sync-console');
            var $spinner  = $console.find('.df-sync-console__spinner');
            var $title    = $console.find('.df-sync-console__title');
            var $eta      = $console.find('.df-sync-console__eta');
            var $fill     = $console.find('.df-sync-console__bar-fill');
            var $pct      = $console.find('.df-sync-console__pct');
            var $stats    = $console.find('.df-sync-console__stats');
            var $log      = $console.find('.df-sync-log');

            function ts() {
                var d = new Date();
                return [d.getHours(), d.getMinutes(), d.getSeconds()]
                    .map(function(n){ return String(n).padStart(2,'0'); }).join(':');
            }

            function logLine(msg, type) {
                type = type || 'default';
                var icons = { success:'✓', error:'✗', info:'→', done:'✓', muted:'·', default:'·' };
                var icon  = icons[type] || '·';
                var $line = $('<div class="df-sync-log-line df-sync-log-line--' + type + '">' +
                    '<span class="df-sync-log__ts">' + ts() + '</span>' +
                    '<span class="df-sync-log__icon">' + icon + '</span>' +
                    '<span class="df-sync-log__msg">' + msg + '</span>' +
                    '</div>');
                $log.prepend($line); /* newest first */
            }

            function setProgress(done, total) {
                var pct = total > 0 ? Math.round((done / total) * 100) : 0;
                $fill.css('width', pct + '%');
                $pct.text(pct + '%');
            }

            function showConsole(label) {
                $console.attr('data-active', '1');
                $spinner.attr('data-done', null).removeAttr('data-done');
                $fill.attr('data-done', null).removeAttr('data-done').css('width', '0%');
                $pct.text('0%');
                $title.text(label);
                $eta.text('');
                $stats.text('Starting…');
                $log.empty();
            }

            function finishConsole(label) {
                $spinner.attr('data-done', '1');
                $fill.attr('data-done', '1').css('width', '100%');
                $pct.text('100%');
                $title.text(label);
                $eta.text('');
            }

            function fmtEta(ms) {
                if (ms < 2000) return '';
                var s = Math.round(ms / 1000);
                return s < 60 ? '~' + s + 's remaining' : '~' + Math.round(s/60) + 'm remaining';
            }

            /* ── Core sync loop ───────────────────────────────── */
            function startBatchSync(type) {
                var label       = type === 'brands' ? 'Brands' : 'Toplists';
                var fetchAction = type === 'brands' ? 'dataflair_fetch_all_brands'  : 'dataflair_fetch_all_toplists';
                var fetchNonce  = type === 'brands' ? fetchBrandsNonce              : fetchToplistsNonce;
                var syncAction  = type === 'brands' ? 'dataflair_sync_brands_batch' : 'dataflair_sync_toplists_batch';
                var syncNonce   = type === 'brands' ? syncBrandsNonce               : syncToplistsNonce;
                var $btn        = type === 'brands' ? $('#df-dash-sync-brands')      : $('#df-dash-sync-toplists');

                $btn.prop('disabled', true).text('Validating…');
                showConsole('Syncing ' + label + '…');
                logLine('Validating API token…', 'info');

                /* Step 1 — token check (FetchAll returns {start_batch:true}, no totals) */
                $.post(ajaxUrl, { action: fetchAction, _ajax_nonce: fetchNonce }, function (res) {
                    if (!res || !res.success) {
                        var errMsg = (res && res.data && res.data.message) ? res.data.message : 'Request failed';
                        logLine('Error: ' + errMsg, 'error');
                        $stats.text('Failed — ' + errMsg);
                        $btn.prop('disabled', false).text('Sync ' + label);
                        return;
                    }

                    logLine('Token OK — starting page sync…', 'info');
                    $btn.text('Syncing…');

                    var syncedTotal = 0;
                    var totalPages  = null; /* resolved from first batch response */
                    var page        = 1;
                    var tStart      = Date.now();

                    /* Step 2 — iterative batch loop */
                    function nextPage() {
                        var tPage = Date.now();
                        $.post(ajaxUrl, { action: syncAction, _ajax_nonce: syncNonce, page: page }, function (r) {
                            var pageMs     = Date.now() - tPage;
                            var data       = (r && r.data) ? r.data : {};

                            /* Server-side failure — surface the message and stop */
                            if (!r || !r.success) {
                                var errMsg = (data.message) ? data.message : 'Server error on page ' + page;
                                logLine('Error on page ' + page + ': ' + errMsg, 'error');
                                $stats.text('Stopped on page ' + page + ' — ' + errMsg);
                                $btn.prop('disabled', false).text('Sync ' + label);
                                return;
                            }

                            var synced     = data.synced     || 0;
                            var errors     = data.errors     || 0;
                            var isComplete = data.is_complete || false;
                            var lastPage   = data.last_page  || page;
                            var nextPageNo = data.next_page  || (page + 1);
                            syncedTotal += synced;

                            /* Derive totalPages from first response */
                            if (totalPages === null) {
                                totalPages = lastPage;
                                logLine('API reports ' + totalPages + ' page(s) to sync', 'info');
                                $stats.text('Page 1 of ' + totalPages + ' · 0 synced');
                            }

                            setProgress(page, totalPages);
                            var lineType = (errors > 0) ? 'error' : 'success';
                            var lineMsg  = 'Page ' + page + '/' + totalPages + '  ·  +' + synced + ' synced';
                            if (errors > 0) lineMsg += '  ·  ⚠ ' + errors + ' errors';
                            if (synced === 0 && errors === 0) lineMsg += '  ·  (skipped — check API format)';
                            lineMsg += '  ·  ' + pageMs + 'ms';
                            logLine(lineMsg, lineType);

                            /* ETA */
                            var elapsed    = Date.now() - tStart;
                            var avgPerPage = elapsed / page;
                            var remaining  = (totalPages - page) * avgPerPage;
                            $stats.text('Page ' + page + ' of ' + totalPages + ' · ' + syncedTotal + ' synced');
                            $eta.text(!isComplete ? fmtEta(remaining) : '');

                            if (!isComplete) {
                                page = nextPageNo;
                                nextPage();
                            } else {
                                var totalSec = ((Date.now() - tStart) / 1000).toFixed(1);
                                logLine('Done — ' + syncedTotal + ' ' + label.toLowerCase() + ' synced in ' + totalSec + 's', 'done');
                                $stats.text(syncedTotal + ' ' + label.toLowerCase() + ' synced in ' + totalSec + 's');
                                finishConsole(label + ' sync complete');
                                $btn.prop('disabled', false).text('Sync ' + label + ' ✓');
                            }
                        }).fail(function () {
                            logLine('Page ' + page + ' request failed (network error)', 'error');
                            $stats.text('Stopped on page ' + page + ' — network error.');
                            $btn.prop('disabled', false).text('Sync ' + label);
                        });
                    }
                    nextPage();

                }).fail(function () {
                    logLine('Network error during token validation', 'error');
                    $stats.text('Network error.');
                    $btn.prop('disabled', false).text('Sync ' + label);
                });
            }

            $('#df-dash-sync-brands').on('click', function () { startBatchSync('brands'); });
            $('#df-dash-sync-toplists').on('click', function () { startBatchSync('toplists'); });

            /* ── API health refresh ───────────────────────────── */
            $('#df-health-refresh').on('click', function () {
                var $tile     = $('#df-health-tile');
                var $checked  = $tile.find('.df-health-checked');
                var prevTxt   = $tile.find('.df-stat-value').text();
                var $btn      = $(this);

                $btn.prop('disabled', true).text('↻ Checking…');

                $.post(ajaxUrl, { action: 'dataflair_api_health', _ajax_nonce: healthNonce, force: 1 }, function (res) {
                    if (res && res.success && res.data) {
                        var s      = res.data.status;
                        var labels = { healthy: 'Healthy', failing: 'Failing', unconfigured: 'Not configured' };
                        var label  = labels[s] || 'Unknown';
                        var txt    = label + (res.data.ping_ms ? ' (' + res.data.ping_ms + ' ms)' : '');
                        $tile.find('.df-stat-value').text(txt);
                        $checked.text('Checked just now');
                        $tile.removeClass('df-tile--pass df-tile--warn df-tile--error')
                             .addClass(s === 'healthy' ? 'df-tile--pass' : s === 'failing' ? 'df-tile--error' : 'df-tile--warn');
                    } else {
                        // Keep previous status visible; surface the failure on the timestamp line.
                        var msg = (res && res.data && res.data.message) ? res.data.message : 'Refresh failed';
                        $tile.find('.df-stat-value').text(prevTxt);
                        $checked.text('Refresh failed: ' + msg);
                    }
                }).fail(function () {
                    $tile.find('.df-stat-value').text(prevTxt);
                    $checked.text('Refresh failed: network error');
                }).always(function () {
                    $btn.prop('disabled', false).text('↻ Refresh');
                });
            });

            /* ── Copy shortcode ───────────────────────────────── */
            $(document).on('click', '.df-copy-shortcode', function () {
                var $btn = $(this);
                navigator.clipboard && navigator.clipboard.writeText($btn.data('text')).then(function () {
                    $btn.text('Copied!');
                    setTimeout(function () { $btn.text('Copy'); }, 2000);
                });
            });
        });
        </script>
        <?php
    }

    private function renderSyncConsole(): void
    {
        ?>
        <div id="df-sync-console" class="df-sync-console">
            <div class="df-sync-console__header">
                <span class="df-sync-console__spinner"></span>
                <span class="df-sync-console__title">Syncing…</span>
                <span class="df-sync-console__eta"></span>
            </div>
            <div class="df-sync-console__progress-wrap">
                <div class="df-sync-console__bar-track">
                    <div class="df-sync-console__bar-fill"></div>
                </div>
                <span class="df-sync-console__pct">0%</span>
            </div>
            <div class="df-sync-console__stats">Starting…</div>
            <div class="df-sync-log"></div>
        </div>
        <?php
    }

    private function renderStatTile(string $label, string $value, string $status, string $href): void
    {
        $cls = match ($status) {
            'pass'  => 'df-tile--pass',
            'warn'  => 'df-tile--warn',
            'error' => 'df-tile--error',
            default => '',
        };
        ?>
        <a href="<?php echo esc_url(admin_url('admin.php' . $href)); ?>" class="df-stat-tile <?php echo esc_attr($cls); ?>">
            <span class="df-tile-label"><?php echo esc_html($label); ?></span>
            <span class="df-tile-value"><?php echo esc_html($value); ?></span>
        </a>
        <?php
    }

    private function renderLastSyncTile(int $last_sync_ts): void
    {
        $value  = $last_sync_ts > 0 ? human_time_diff($last_sync_ts) . ' ago' : 'Never';
        $status = $last_sync_ts > 0 ? 'pass' : 'warn';
        ?>
        <div class="df-stat-tile <?php echo $status === 'pass' ? 'df-tile--pass' : 'df-tile--warn'; ?>">
            <span class="df-tile-label">Last Sync</span>
            <span class="df-tile-value"><?php echo esc_html($value); ?></span>
            <span class="df-tile-sub">Use Dashboard or WP-CLI to sync</span>
        </div>
        <?php
    }

    private function renderApiHealthTile(array $health): void
    {
        $status = $health['status'];
        $cls    = match ($status) {
            'healthy'      => 'df-tile--pass',
            'failing'      => 'df-tile--error',
            'unconfigured' => 'df-tile--warn',
            default        => '',
        };
        $label = match ($status) {
            'healthy'      => 'Healthy',
            'failing'      => 'Failing',
            'unconfigured' => 'Not configured',
            default        => 'Not yet checked',
        };
        $ping       = ($health['ping_ms'] ?? 0) > 0 ? ' (' . (int) $health['ping_ms'] . ' ms)' : '';
        $checked_at = (int) ($health['checked_at'] ?? 0);
        $checked    = $checked_at > 0 ? 'Checked ' . human_time_diff($checked_at) . ' ago' : 'Never checked';
        ?>
        <div id="df-health-tile" class="df-stat-tile <?php echo esc_attr($cls); ?>">
            <span class="df-tile-label">API Health</span>
            <span class="df-stat-value df-tile-value"><?php echo esc_html($label . $ping); ?></span>
            <span class="df-tile-sub df-health-checked"><?php echo esc_html($checked); ?></span>
            <button type="button" id="df-health-refresh" class="button button-small df-tile-refresh">↻ Refresh</button>
        </div>
        <?php
    }
}
