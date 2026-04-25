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

        $api_health = get_transient('dataflair_api_health');
        if (!is_array($api_health)) {
            $api_health = ['status' => 'unknown', 'ping_ms' => 0, 'error' => ''];
        }

        $history = get_option('dataflair_sync_history', []);
        if (!is_array($history)) {
            $history = [];
        }
        $recent = array_slice($history, 0, 5);

        $cron_brands   = wp_next_scheduled('dataflair_cron_sync_brands');
        $cron_toplists = wp_next_scheduled('dataflair_cron_sync_toplists');

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

            <?php $this->renderSyncProgress(); ?>

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
                <?php $this->renderLastSyncTile($last_sync_ts, $cron_brands, $cron_toplists); ?>
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
                        <h2 class="df-card__title">Scheduled Jobs</h2>
                    </div>
                    <ul class="df-jobs-list">
                        <li>
                            <span class="df-jobs-label">Brands sync</span>
                            <span class="df-jobs-value"><?php echo $cron_brands ? esc_html('Next: ' . human_time_diff($cron_brands)) : '<span class="df-text-muted">Not scheduled</span>'; ?></span>
                        </li>
                        <li>
                            <span class="df-jobs-label">Toplists sync</span>
                            <span class="df-jobs-value"><?php echo $cron_toplists ? esc_html('Next: ' . human_time_diff($cron_toplists)) : '<span class="df-text-muted">Not scheduled</span>'; ?></span>
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
            var ajaxUrl = <?php echo json_encode(admin_url('admin-ajax.php')); ?>;
            var fetchBrandsNonce   = <?php echo json_encode(wp_create_nonce('dataflair_fetch_all_brands')); ?>;
            var syncBrandsNonce    = <?php echo json_encode(wp_create_nonce('dataflair_sync_brands_batch')); ?>;
            var fetchToplistsNonce = <?php echo json_encode(wp_create_nonce('dataflair_fetch_all_toplists')); ?>;
            var syncToplistsNonce  = <?php echo json_encode(wp_create_nonce('dataflair_sync_toplists_batch')); ?>;
            var healthNonce        = <?php echo json_encode(wp_create_nonce('dataflair_api_health')); ?>;

            function startBatchSync(type) {
                var fetchAction = type === 'brands' ? 'dataflair_fetch_all_brands' : 'dataflair_fetch_all_toplists';
                var fetchNonce  = type === 'brands' ? fetchBrandsNonce : fetchToplistsNonce;
                var syncAction  = type === 'brands' ? 'dataflair_sync_brands_batch' : 'dataflair_sync_toplists_batch';
                var syncNonce   = type === 'brands' ? syncBrandsNonce : syncToplistsNonce;
                var $btn = type === 'brands' ? $('#df-dash-sync-brands') : $('#df-dash-sync-toplists');
                $btn.prop('disabled', true).text('Fetching…');

                $.post(ajaxUrl, { action: fetchAction, _ajax_nonce: fetchNonce }, function (res) {
                    if (!res.success) { $btn.prop('disabled', false).text(type === 'brands' ? 'Sync Brands' : 'Sync Toplists'); return; }
                    var total = res.data ? (res.data.total || 0) : 0;
                    var done = 0, page = 1;
                    $btn.text('Syncing 0/' + total + '…');
                    function nextPage() {
                        $.post(ajaxUrl, { action: syncAction, _ajax_nonce: syncNonce, page: page }, function (r) {
                            if (r && r.data) done += (r.data.synced || 0);
                            $btn.text('Syncing ' + done + '/' + total + '…');
                            if (r && r.data && r.data.has_more) { page++; nextPage(); }
                            else { $btn.prop('disabled', false).text(type === 'brands' ? 'Sync Brands ✓' : 'Sync Toplists ✓'); }
                        });
                    }
                    nextPage();
                });
            }

            $('#df-dash-sync-brands').on('click', function () { startBatchSync('brands'); });
            $('#df-dash-sync-toplists').on('click', function () { startBatchSync('toplists'); });

            /* API health refresh */
            $('#df-health-refresh').on('click', function () {
                var $tile = $('#df-health-tile');
                $tile.find('.df-stat-value').text('Checking…');
                $.post(ajaxUrl, { action: 'dataflair_api_health', _ajax_nonce: healthNonce }, function (res) {
                    if (res.success && res.data) {
                        var s = res.data.status;
                        var label = s === 'healthy' ? 'Healthy' : s === 'failing' ? 'Failing' : 'Unknown';
                        $tile.find('.df-stat-value').text(label + (res.data.ping_ms ? ' (' + res.data.ping_ms + ' ms)' : ''));
                    }
                });
            });

            /* Copy shortcode */
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

    private function renderSyncProgress(): void
    {
        ?>
        <div id="df-dash-sync-progress" style="display:none;margin-bottom:16px;max-width:400px;">
            <div style="background:#f0f0f1;border-radius:3px;height:20px;overflow:hidden;position:relative;">
                <div id="df-dash-progress-bar" style="background:#2271b1;width:0%;height:100%;transition:width 0.3s;"></div>
            </div>
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

    private function renderLastSyncTile(int $last_sync_ts, int|false $cron_brands, int|false $cron_toplists): void
    {
        $value  = $last_sync_ts > 0 ? human_time_diff($last_sync_ts) . ' ago' : 'Never';
        $next   = min(
            $cron_brands   ?: PHP_INT_MAX,
            $cron_toplists ?: PHP_INT_MAX
        );
        $status = $last_sync_ts > 0 ? 'pass' : 'warn';
        ?>
        <div class="df-stat-tile <?php echo $status === 'pass' ? 'df-tile--pass' : 'df-tile--warn'; ?>">
            <span class="df-tile-label">Last Sync</span>
            <span class="df-tile-value"><?php echo esc_html($value); ?></span>
            <?php if ($next < PHP_INT_MAX): ?>
                <span class="df-tile-sub">Next: <?php echo esc_html(human_time_diff($next)); ?></span>
            <?php endif; ?>
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
            default        => 'Unknown',
        };
        $ping = $health['ping_ms'] > 0 ? ' (' . $health['ping_ms'] . ' ms)' : '';
        ?>
        <div id="df-health-tile" class="df-stat-tile <?php echo esc_attr($cls); ?>">
            <span class="df-tile-label">API Health</span>
            <span class="df-stat-value df-tile-value"><?php echo esc_html($label . $ping); ?></span>
            <button type="button" id="df-health-refresh" class="button button-small df-tile-refresh">↻ Refresh</button>
        </div>
        <?php
    }
}
