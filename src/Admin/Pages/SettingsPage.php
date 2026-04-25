<?php
/**
 * Phase 9.6 (admin UX redesign) — Settings page.
 *
 * Three tabs:
 *   api_connection  — Bearer token, base URL, brands API version
 *   customizations  — Ribbon/CTA color defaults
 *   sync_schedule   — WP-CLI sync reference, retry count, alert email
 *
 * The Sync Toplists section and the toplists table have moved to
 * ToplistsListPage. The API Preview tab has moved to ToolsPage.
 *
 * Backward compat: `?page=dataflair-toplists&tab=api` and
 * `?page=dataflair-toplists&tab=customizations` redirect here via
 * MenuRegistrar::addBackwardCompatRedirects().
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Pages;

final class SettingsPage implements PageInterface
{
    public function __construct(
        private \Closure $apiBaseUrlResolver,
        private \Closure $lastSyncLabelFormatter
    ) {}

    public function render(): void
    {
        $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'api_connection';
        // Map legacy tab names to new ones for any remaining direct hits.
        if ($current_tab === 'api') {
            $current_tab = 'api_connection';
        }
        ?>
        <div class="wrap">
            <div class="df-page-header">
                <h1 class="df-page-header__title">Settings</h1>
            </div>

            <?php settings_errors(); ?>

            <nav class="nav-tab-wrapper">
                <a href="?page=dataflair-settings&tab=api_connection" class="nav-tab <?php echo $current_tab === 'api_connection' ? 'nav-tab-active' : ''; ?>">
                    API Connection
                </a>
                <a href="?page=dataflair-settings&tab=customizations" class="nav-tab <?php echo $current_tab === 'customizations' ? 'nav-tab-active' : ''; ?>">
                    Customizations
                </a>
                <a href="?page=dataflair-settings&tab=sync_schedule" class="nav-tab <?php echo $current_tab === 'sync_schedule' ? 'nav-tab-active' : ''; ?>">
                    Sync
                </a>
            </nav>

            <form id="dataflair-settings-form" method="post" action="options.php">
                <?php settings_fields('dataflair_settings'); ?>

                <?php if ($current_tab === 'api_connection'): ?>
                    <!-- API Connection Tab -->
                    <style>
                        #dataflair_api_base_url::placeholder { color: #d3d3d3; }
                        #dataflair_api_base_url::-webkit-input-placeholder { color: #d3d3d3; }
                        #dataflair_api_base_url::-moz-placeholder { color: #d3d3d3; opacity: 1; }
                        #dataflair_api_base_url:-ms-input-placeholder { color: #d3d3d3; }
                    </style>
                    <div class="tab-content">
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="dataflair_api_token">API Bearer Token</label>
                                </th>
                                <td>
                                    <input type="text"
                                           id="dataflair_api_token"
                                           name="dataflair_api_token"
                                           value="<?php echo esc_attr(get_option('dataflair_api_token')); ?>"
                                           class="regular-text">
                                    <p class="description">Your DataFlair API bearer token</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="dataflair_api_base_url">API Base URL</label>
                                </th>
                                <td>
                                    <input type="text"
                                           id="dataflair_api_base_url"
                                           name="dataflair_api_base_url"
                                           value="<?php echo esc_attr(get_option('dataflair_api_base_url', '')); ?>"
                                           class="regular-text"
                                           placeholder="https://tenant.dataflair.ai/api/v1">
                                    <p class="description">
                                        Your DataFlair API base URL (e.g., https://tenant.dataflair.ai/api/v1).
                                        Leave empty to auto-detect from token or stored endpoints.
                                        <?php
                                        $current_base = get_option('dataflair_api_base_url');
                                        if (!empty($current_base)) {
                                            echo '<br><strong>Current: ' . esc_html($current_base) . '</strong>';
                                        }
                                        ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Brands API Version</th>
                                <td>
                                    <label>
                                        <input type="radio" name="dataflair_brands_api_version"
                                               value="v1" <?php checked(get_option('dataflair_brands_api_version', 'v1'), 'v1'); ?>>
                                        V1 <span style="color:#646970;">(default)</span>
                                    </label>
                                    &nbsp;&nbsp;
                                    <label>
                                        <input type="radio" name="dataflair_brands_api_version"
                                               value="v2" <?php checked(get_option('dataflair_brands_api_version', 'v1'), 'v2'); ?>>
                                        V2
                                    </label>
                                    <p class="description">
                                        V2 includes classificationTypes, 15 multi-vertical brand fields
                                        (sports, poker, sweeps-coins) and unified offer types.
                                        Requires DataFlair API &ge; v2.
                                    </p>
                                </td>
                            </tr>
                        </table>

                        <p style="margin-top:16px;">
                            <?php submit_button('Save Settings', 'primary', 'submit', false, ['id' => 'dataflair-save-settings', 'style' => 'margin:0;']); ?>
                            <button type="button" id="df-test-connection" class="button" style="margin-left:8px;">Test Connection</button>
                            <span id="df-test-connection-result" style="margin-left:10px;"></span>
                            <span id="dataflair-save-message" style="margin-left:10px;"></span>
                        </p>
                    </div>
                    <script>
                    jQuery(document).ready(function ($) {
                        $('#df-test-connection').on('click', function () {
                            var $btn = $(this), $result = $('#df-test-connection-result');
                            $btn.prop('disabled', true).text('Testing…');
                            $result.text('');
                            $.post(<?php echo json_encode(admin_url('admin-ajax.php')); ?>, {
                                action: 'dataflair_test_api_connection',
                                _ajax_nonce: <?php echo json_encode(wp_create_nonce('dataflair_test_api_connection')); ?>,
                            }, function (res) {
                                $btn.prop('disabled', false).text('Test Connection');
                                if (!res.success) { $result.html('<span style="color:#b32d2e;">' + (res.data.message || 'Error') + '</span>'); return; }
                                var d = res.data;
                                var ok = d.status_code >= 200 && d.status_code < 400;
                                var cls = ok ? 'color:#00a32a' : 'color:#b32d2e';
                                var msg = ok ? '✓ Connected (HTTP ' + d.status_code + ', ' + d.ms + ' ms)' : '✗ ' + (d.error || 'HTTP ' + d.status_code);
                                $result.html('<span style="' + cls + ';font-weight:600;">' + msg + '</span>');
                            });
                        });
                    });
                    </script>

                <?php elseif ($current_tab === 'customizations'): ?>
                    <!-- Customizations Tab -->
                    <div class="tab-content">
                        <h2>Default Customization Options</h2>
                        <p class="description">These settings will be used as defaults for all toplist blocks. You can override them per block in the block settings. Use Tailwind CSS color classes (e.g., "brand-600", "blue-500", "bg-[#ff0000]").</p>

                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="dataflair_ribbon_bg_color">Ribbon Background Color</label>
                                </th>
                                <td>
                                    <input type="text"
                                           id="dataflair_ribbon_bg_color"
                                           name="dataflair_ribbon_bg_color"
                                           value="<?php echo esc_attr(get_option('dataflair_ribbon_bg_color', 'brand-600')); ?>"
                                           class="regular-text"
                                           placeholder="brand-600">
                                    <p class="description">Default background color for the "Our Top Choice" ribbon (e.g., "brand-600", "blue-500", "bg-[#ff0000]")</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="dataflair_ribbon_text_color">Ribbon Text Color</label>
                                </th>
                                <td>
                                    <input type="text"
                                           id="dataflair_ribbon_text_color"
                                           name="dataflair_ribbon_text_color"
                                           value="<?php echo esc_attr(get_option('dataflair_ribbon_text_color', 'white')); ?>"
                                           class="regular-text"
                                           placeholder="white">
                                    <p class="description">Default text color for the ribbon (e.g., "white", "gray-900", "text-[#ffffff]")</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="dataflair_cta_bg_color">CTA Button Background Color</label>
                                </th>
                                <td>
                                    <input type="text"
                                           id="dataflair_cta_bg_color"
                                           name="dataflair_cta_bg_color"
                                           value="<?php echo esc_attr(get_option('dataflair_cta_bg_color', 'brand-600')); ?>"
                                           class="regular-text"
                                           placeholder="brand-600">
                                    <p class="description">Default background color for the "Visit Site" button (e.g., "brand-600", "green-500", "bg-[#00ff00]")</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="dataflair_cta_text_color">CTA Button Text Color</label>
                                </th>
                                <td>
                                    <input type="text"
                                           id="dataflair_cta_text_color"
                                           name="dataflair_cta_text_color"
                                           value="<?php echo esc_attr(get_option('dataflair_cta_text_color', 'white')); ?>"
                                           class="regular-text"
                                           placeholder="white">
                                    <p class="description">Default text color for the CTA button (e.g., "white", "gray-900", "text-[#ffffff]")</p>
                                </td>
                            </tr>
                        </table>

                        <?php submit_button('Save Settings', 'primary', 'submit', false, ['id' => 'dataflair-save-settings-custom']); ?>
                        <span id="dataflair-save-message-custom" style="margin-left: 10px;"></span>
                    </div>

                <?php else: ?>
                    <!-- Sync Tab -->
                    <div class="tab-content" style="padding-top:16px;">
                        <h2>Sync</h2>
                        <p class="description">
                            DataFlair sync is triggered manually — from the Dashboard or via WP-CLI.
                            There is no automatic WP-Cron schedule. Add the commands below to your
                            server crontab to run sync on a schedule.
                        </p>

                        <h3 style="margin-top:20px;">WP-CLI Commands</h3>
                        <table class="widefat striped" style="max-width:680px;margin-bottom:20px;">
                            <thead><tr><th>Action</th><th>Command</th></tr></thead>
                            <tbody>
                                <tr>
                                    <td>Sync all toplists</td>
                                    <td><code>wp dataflair sync toplists</code></td>
                                </tr>
                                <tr>
                                    <td>Sync all brands</td>
                                    <td><code>wp dataflair sync brands</code></td>
                                </tr>
                                <tr>
                                    <td>Sync both</td>
                                    <td><code>wp dataflair sync all</code></td>
                                </tr>
                                <tr>
                                    <td>Check API health</td>
                                    <td><code>wp dataflair health</code></td>
                                </tr>
                            </tbody>
                        </table>

                        <h3>Example crontab entry (hourly toplists)</h3>
                        <pre style="background:#f6f7f7;padding:12px 14px;border-radius:3px;font-size:12px;line-height:1.6;max-width:680px;overflow-x:auto;">0 * * * * cd /var/www/html &amp;&amp; wp dataflair sync toplists --allow-root --quiet</pre>

                        <h3 style="margin-top:20px;">Sync Options</h3>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="dataflair_sync_retry_count">Retry Count</label></th>
                                <td>
                                    <input type="number" id="dataflair_sync_retry_count" name="dataflair_sync_retry_count"
                                           value="<?php echo esc_attr(get_option('dataflair_sync_retry_count', '3')); ?>"
                                           class="small-text" min="0" max="10">
                                    <p class="description">Number of retry attempts per failed API page request (0 = no retries).</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="dataflair_sync_alert_email">Alert Email</label></th>
                                <td>
                                    <input type="email" id="dataflair_sync_alert_email" name="dataflair_sync_alert_email"
                                           value="<?php echo esc_attr(get_option('dataflair_sync_alert_email', '')); ?>"
                                           class="regular-text" placeholder="admin@example.com">
                                    <p class="description">Receive an email when a sync exhausts all retries. Leave empty to disable.</p>
                                </td>
                            </tr>
                        </table>
                        <?php submit_button('Save', 'primary', 'submit', false, ['id' => 'dataflair-save-schedule']); ?>
                        <span id="dataflair-save-message-schedule" style="margin-left:10px;"></span>
                    </div>
                <?php endif; ?>
            </form>
        </div>
        <?php
    }
}
