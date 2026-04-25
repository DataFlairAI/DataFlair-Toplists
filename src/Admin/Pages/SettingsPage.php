<?php
/**
 * Phase 9.6 (admin UX redesign) — Settings page.
 *
 * Three tabs:
 *   api_connection  — Bearer token, base URL, brands API version
 *   customizations  — Ribbon/CTA color defaults
 *   sync_schedule   — Cadence selects, retry count, alert email (Phase 4 form)
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
                    Sync Schedule
                </a>
            </nav>

            <form id="dataflair-settings-form" method="post" action="options.php">
                <?php wp_nonce_field('dataflair_save_settings', 'dataflair_settings_nonce'); ?>

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

                        <?php submit_button('Save Settings', 'primary', 'submit', false, ['id' => 'dataflair-save-settings']); ?>
                        <span id="dataflair-save-message" style="margin-left: 10px;"></span>
                    </div>

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
                    <!-- Sync Schedule Tab (Phase 4 — full form coming later) -->
                    <div class="tab-content" style="padding-top:16px;">
                        <h2>Sync Schedule</h2>
                        <p class="description">
                            Configure automatic sync cadences for Brands and Toplists, the retry
                            budget, and an alert email for persistent failures. Full form coming
                            in a subsequent release.
                        </p>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="dataflair_brands_sync_cadence">Brands Sync Cadence</label></th>
                                <td>
                                    <select id="dataflair_brands_sync_cadence" name="dataflair_brands_sync_cadence">
                                        <option value="hourly"        <?php selected(get_option('dataflair_brands_sync_cadence', 'six_hours'), 'hourly'); ?>>Hourly</option>
                                        <option value="six_hours"     <?php selected(get_option('dataflair_brands_sync_cadence', 'six_hours'), 'six_hours'); ?>>Every 6 hours (default)</option>
                                        <option value="twelve_hours"  <?php selected(get_option('dataflair_brands_sync_cadence', 'six_hours'), 'twelve_hours'); ?>>Every 12 hours</option>
                                        <option value="daily_3am"     <?php selected(get_option('dataflair_brands_sync_cadence', 'six_hours'), 'daily_3am'); ?>>Daily at 3 AM</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="dataflair_toplists_sync_cadence">Toplists Sync Cadence</label></th>
                                <td>
                                    <select id="dataflair_toplists_sync_cadence" name="dataflair_toplists_sync_cadence">
                                        <option value="hourly"        <?php selected(get_option('dataflair_toplists_sync_cadence', 'hourly'), 'hourly'); ?>>Hourly (default)</option>
                                        <option value="six_hours"     <?php selected(get_option('dataflair_toplists_sync_cadence', 'hourly'), 'six_hours'); ?>>Every 6 hours</option>
                                        <option value="twelve_hours"  <?php selected(get_option('dataflair_toplists_sync_cadence', 'hourly'), 'twelve_hours'); ?>>Every 12 hours</option>
                                        <option value="daily_3am"     <?php selected(get_option('dataflair_toplists_sync_cadence', 'hourly'), 'daily_3am'); ?>>Daily at 3 AM</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="dataflair_sync_retry_count">Retry Count</label></th>
                                <td>
                                    <input type="number" id="dataflair_sync_retry_count" name="dataflair_sync_retry_count"
                                           value="<?php echo esc_attr(get_option('dataflair_sync_retry_count', '3')); ?>"
                                           class="small-text" min="0" max="10">
                                    <p class="description">Number of retry attempts per failed sync request (0 = no retries).</p>
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
                        <p class="description" style="color:#646970;margin-top:16px;">
                            Note: saving cron rescheduling (Phase 4) is not yet wired. Changes to cadence
                            above take effect in the next release.
                        </p>
                    </div>
                <?php endif; ?>
            </form>
        </div>
        <?php
    }
}
