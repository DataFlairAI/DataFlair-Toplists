<?php
/**
 * Phase 9.6 — Main DataFlair settings screen.
 *
 * Renders the admin "DataFlair → Toplists" page: API token + base URL form,
 * Brands API version toggle, Fetch-All-Toplists trigger, the synced-toplists
 * accordion table, and the API Preview / V1↔V2 compare tab. Extracted
 * verbatim from `DataFlair_Toplists::settings_page()` so HTML output stays
 * byte-identical to v2.1.1.
 *
 * Two god-class private methods are still needed inside the body —
 * `get_api_base_url()` and `format_last_sync_label()`. Both are injected as
 * closures so this class never depends on the legacy class symbol; the
 * shim-drop in v3.0.0 will rebind these to their `Http\` / `Frontend\Render\`
 * homes without touching the page.
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
        global $wpdb;
        $table_name = $wpdb->prefix . DATAFLAIR_TABLE_NAME;

        // Phase 0B H6: project lean columns + JSON-extract the one data path
        // the settings list needs (template.name) so we never pull the full
        // payload blob. Prior `SELECT *` could page-fault the whole toplist
        // table into memory whenever an admin clicked Settings.
        $toplists = $wpdb->get_results(
            "SELECT id, api_toplist_id, name, slug, version, status,
                    last_synced, item_count, locked_count, sync_warnings,
                    current_period,
                    JSON_UNQUOTE(JSON_EXTRACT(data, '$.data.template.name')) AS template_name
             FROM $table_name
             ORDER BY api_toplist_id ASC"
        );

        // Get current tab from URL
        $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'api';

        ?>
        <div class="wrap">
            <h1>DataFlair Toplists Settings</h1>

            <?php settings_errors(); ?>

            <nav class="nav-tab-wrapper">
                <a href="?page=dataflair-toplists&tab=api" class="nav-tab <?php echo $current_tab === 'api' ? 'nav-tab-active' : ''; ?>">
                    API Settings
                </a>
                <a href="?page=dataflair-toplists&tab=customizations" class="nav-tab <?php echo $current_tab === 'customizations' ? 'nav-tab-active' : ''; ?>">
                    Customizations
                </a>
                <a href="?page=dataflair-toplists&tab=api_preview" class="nav-tab <?php echo $current_tab === 'api_preview' ? 'nav-tab-active' : ''; ?>">
                    API Preview
                </a>
            </nav>

            <form id="dataflair-settings-form">

                <?php if ($current_tab === 'api'): ?>
                    <!-- API Settings Tab -->
                    <style>
                        #dataflair_api_base_url::placeholder {
                            color: #d3d3d3;
                        }
                        #dataflair_api_base_url::-webkit-input-placeholder {
                            color: #d3d3d3;
                        }
                        #dataflair_api_base_url::-moz-placeholder {
                            color: #d3d3d3;
                            opacity: 1;
                        }
                        #dataflair_api_base_url:-ms-input-placeholder {
                            color: #d3d3d3;
                        }
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
                                       value="v1" <?php checked(get_option('dataflair_brands_api_version','v1'), 'v1'); ?>>
                                V1 <span style="color:#646970;">(default)</span>
                            </label>
                            &nbsp;&nbsp;
                            <label>
                                <input type="radio" name="dataflair_brands_api_version"
                                       value="v2" <?php checked(get_option('dataflair_brands_api_version','v1'), 'v2'); ?>>
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

                <?php submit_button('Save Settings', 'primary', 'submit', false, array('id' => 'dataflair-save-settings')); ?>
                <span id="dataflair-save-message" style="margin-left: 10px;"></span>
                    </div>

            <hr>

            <h2>Sync Toplists</h2>
                    <button type="button" id="dataflair-fetch-all-toplists" class="button button-primary">
                        Fetch All Toplists from API
                    </button>
                    <span id="dataflair-fetch-message" style="margin-left: 10px;"></span>
                    <div id="dataflair-toplist-sync-progress" style="display: none; margin-top: 15px; max-width: 400px; background: #f0f0f1; border-radius: 3px; height: 20px; overflow: hidden; position: relative;">
                        <div id="dataflair-toplist-progress-bar" style="background: #2271b1; width: 0%; height: 100%; transition: width 0.3s ease;"></div>
                        <div id="dataflair-toplist-progress-text" style="position: absolute; top: 0; left: 0; width: 100%; text-align: center; color: #fff; font-size: 12px; line-height: 20px; font-weight: 600; text-shadow: 0 0 2px rgba(0,0,0,0.5);">0%</div>
                    </div>
                    <p class="description">Fetches all toplists from the DataFlair API. Existing toplists will be updated.</p>
                    <p class="description">Sync runs only when triggered here or via WP-CLI. <?php echo esc_html(($this->lastSyncLabelFormatter)('dataflair_last_toplists_sync')); ?></p>

            <hr>

            <h2>Synced Toplists</h2>
            <?php if ($toplists): ?>
                <?php
                // Collect unique template names for the filter dropdown
                $all_templates = array();
                foreach ($toplists as $tl) {
                    $tl_data = json_decode($tl->data, true);
                    $tname = isset($tl_data['data']['template']['name']) ? $tl_data['data']['template']['name'] : '';
                    if ($tname) $all_templates[] = $tname;
                }
                $all_templates = array_values(array_unique($all_templates));
                sort($all_templates);
                ?>

                <!-- Template filter -->
                <div class="dataflair-filters" style="margin-bottom: 16px;">
                    <div class="filter-row">
                        <div class="filter-group" style="max-width: 320px;">
                            <label style="font-weight:600;">Template</label>
                            <select id="dataflair-filter-template" class="dataflair-toplist-select2" style="width:100%;">
                                <?php foreach ($all_templates as $tname): ?>
                                    <option value="<?php echo esc_attr($tname); ?>"><?php echo esc_html($tname); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group filter-actions" style="flex:0; align-self:flex-end;">
                            <button type="button" id="dataflair-clear-toplist-filters" class="button">Clear</button>
                        </div>
                        <div class="filter-group" style="flex:0; align-self:flex-end; white-space:nowrap;">
                            <span id="dataflair-toplists-count" style="color:#646970;">Showing <?php echo count($toplists); ?> toplists</span>
                        </div>
                    </div>
                </div>

                <table class="wp-list-table widefat fixed striped dataflair-toplists-table">
                    <thead>
                        <tr>
                            <th style="width: 40px;"></th>
                            <th>WP ID</th>
                            <th>API ID</th>
                            <th style="width: 40%;">Name</th>
                            <th class="sortable-toplist">
                                <a href="#" class="toplist-sort-link" data-sort="template">Template <span class="toplist-sort-indicator"></span></a>
                            </th>
                            <th>Period</th>
                            <th>Version</th>
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
                            // Phase 0B H6: template_name is JSON-extracted in SELECT; $toplist->data no longer populated.
                            $template_name = isset($toplist->template_name) ? (string) $toplist->template_name : '';

                            // Prefer extracted columns; fall back to 0 if legacy rows predate the extracted columns.
                            $items_count  = isset($toplist->item_count)   ? (int) $toplist->item_count   : 0;
                            $locked_count = isset($toplist->locked_count) ? (int) $toplist->locked_count : 0;

                            // Sync health
                            $sync_warnings_raw  = isset($toplist->sync_warnings) ? $toplist->sync_warnings : null;
                            $sync_warnings_arr  = (!empty($sync_warnings_raw)) ? json_decode($sync_warnings_raw, true) : [];
                            $warning_count      = is_array($sync_warnings_arr) ? count($sync_warnings_arr) : 0;
                            $last_synced_ts     = $toplist->last_synced ? strtotime($toplist->last_synced) : 0;
                            $is_stale           = $last_synced_ts && (time() - $last_synced_ts) > 3600;

                            if ($is_stale) {
                                $health_html = '<span style="color:#d63638;" title="Last sync was over 1 hour ago">&#128308; Stale</span>';
                            } elseif ($warning_count > 0) {
                                $health_html = '<a href="#" class="toplist-warnings-toggle" data-toplist-id="' . esc_attr($toplist->id) . '" style="color:#dba617; text-decoration:none;" title="Click to view warnings">&#9888;&#65039; ' . $warning_count . ' warning' . ($warning_count !== 1 ? 's' : '') . '</a>';
                            } else {
                                $health_html = '<span style="color:#00a32a;" title="All data validated OK">&#9989;</span>';
                            }
                        ?>
                        <tr class="toplist-row"
                            data-toplist-id="<?php echo esc_attr($toplist->id); ?>"
                            data-template="<?php echo esc_attr($template_name); ?>"
                            data-items="<?php echo esc_attr($items_count); ?>"
                            data-last-synced="<?php echo esc_attr($toplist->last_synced); ?>">
                            <td>
                                <button type="button" class="toplist-toggle-btn" title="View Details">
                                    <span class="dashicons dashicons-arrow-right"></span>
                                </button>
                            </td>
                            <td><?php echo esc_html($toplist->id); ?></td>
                            <td><?php echo esc_html($toplist->api_toplist_id); ?></td>
                            <td><?php echo esc_html($toplist->name); ?></td>
                            <td><?php echo esc_html($template_name ?: '—'); ?></td>
                            <td><?php echo isset($toplist->current_period) && !empty($toplist->current_period) ? esc_html($toplist->current_period) : '<span style="color:#999;">—</span>'; ?></td>
                            <td><?php echo esc_html($toplist->version); ?></td>
                            <td><?php echo esc_html($items_count); ?></td>
                            <td><?php echo esc_html($toplist->last_synced); ?></td>
                        </tr>
                        <?php if ($warning_count > 0): ?>
                        <tr class="toplist-warnings-row" id="warnings-<?php echo esc_attr($toplist->id); ?>" style="display:none;">
                            <td colspan="9" style="padding: 0;">
                                <div style="padding: 12px 20px; background: #fff8e5; border-left: 4px solid #dba617;">
                                    <strong style="color:#dba617;">&#9888;&#65039; Sync warnings for <?php echo esc_html($toplist->name); ?>:</strong>
                                    <ul style="margin: 8px 0 0 20px; padding: 0;">
                                        <?php foreach ($sync_warnings_arr as $w): ?>
                                        <li style="font-family: monospace; font-size: 12px; margin: 2px 0;"><?php echo esc_html($w); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr class="toplist-accordion-content" data-toplist-id="<?php echo esc_attr($toplist->id); ?>" style="display: none;">
                            <td colspan="9" style="padding: 0;">
                                <div class="toplist-accordion-inner" style="padding: 20px; background: #f9f9f9; border-left: 4px solid #0073aa;">
                                    <h3 style="margin-top: 0;">Alternative Toplists for Different Geos</h3>
                                    <p class="description">Set alternative toplists to show when a user from a specific geo visits a page where this toplist is displayed.</p>

                                    <div class="alternative-toplists-list"></div>

                                    <div class="add-alternative-toplist" style="margin-top: 20px; padding: 15px; background: white; border: 1px solid #ddd;">
                                        <h4 style="margin-top: 0;">Add Alternative Toplist</h4>
                                        <table class="form-table" style="margin: 0;">
                                            <tr>
                                                <th scope="row"><label>Geo / Market</label></th>
                                                <td>
                                                    <select class="alt-geo-select" style="min-width: 200px;">
                                                        <option value="">Select a geo...</option>
                                                    </select>
                                                    <p class="description">Select the geo/market for which to show an alternative toplist.</p>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th scope="row"><label>Alternative Toplist</label></th>
                                                <td>
                                                    <select class="alt-toplist-select" style="min-width: 300px;">
                                                        <option value="">Select a toplist...</option>
                                                        <?php foreach ($toplists as $alt_toplist): ?>
                                                            <option value="<?php echo esc_attr($alt_toplist->id); ?>">
                                                                <?php echo esc_html($alt_toplist->name); ?> (ID: <?php echo esc_html($alt_toplist->api_toplist_id); ?>)
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <p class="description">Select which toplist to show for users from the selected geo.</p>
                                                </td>
                                            </tr>
                                        </table>
                                        <button type="button" class="button button-primary save-alternative-toplist">Add Alternative</button>
                                        <span class="alt-save-message" style="margin-left: 10px;"></span>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No toplists synced yet. Click "Fetch All Toplists from API" to discover and sync available toplists.</p>
            <?php endif; ?>

            <hr>

            <h2>Shortcode Usage</h2>
            <p>Use the shortcode with these parameters:</p>
            <ul>
                <li><strong>id</strong> (required unless <em>slug</em> is used): API toplist ID</li>
                <li><strong>slug</strong> (optional): Toplist slug (alternative to id)</li>
                <li><strong>title</strong> (optional): Custom title for the toplist</li>
                <li><strong>limit</strong> (optional): Number of casinos to show (default: all)</li>
            </ul>
            <p>By ID: <code>[dataflair_toplist id="3" title="Best UK Casinos" limit="5"]</code></p>
            <p>By slug: <code>[dataflair_toplist slug="brazil-casinos"]</code></p>

            <script>
            jQuery(document).ready(function($) {
                $(document).on('click', '.toplist-warnings-toggle', function(e) {
                    e.preventDefault();
                    var id = $(this).data('toplist-id');
                    $('#warnings-' + id).toggle();
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

                        <?php submit_button('Save Settings', 'primary', 'submit', false, array('id' => 'dataflair-save-settings-custom')); ?>
                        <span id="dataflair-save-message-custom" style="margin-left: 10px;"></span>
                    </div>
                <?php elseif ($current_tab === 'api_preview'): ?>
                    <!-- API Preview Tab -->
                    <div class="tab-content">
                        <h2>API Response Preview</h2>
                        <p class="description">Fetch a live response from the DataFlair API using your stored token. Select an endpoint and click <strong>Fetch Preview</strong> to inspect the raw JSON.</p>

                        <?php
                        $token = trim(get_option('dataflair_api_token', ''));
                        $base_url = ($this->apiBaseUrlResolver)();
                        if (empty($token)): ?>
                            <div class="notice notice-warning inline"><p>No API token configured. Set your token on the <a href="?page=dataflair-toplists&tab=api">API Settings</a> tab first.</p></div>
                        <?php else: ?>
                        <!-- Mode toggle -->
                        <div style="margin-bottom:16px;">
                            <label>
                                <input type="radio" name="df-preview-mode" value="single" checked> Single endpoint
                            </label>
                            &nbsp;&nbsp;
                            <label>
                                <input type="radio" name="df-preview-mode" value="compare"> Compare V1 vs V2
                            </label>
                        </div>
                        <!-- Single mode panel -->
                        <div id="df-single-panel">
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="df-preview-endpoint">Endpoint</label></th>
                                <td>
                                    <select id="df-preview-endpoint" style="min-width:320px;">
                                        <option value="toplists">GET /toplists (list all)</option>
                                        <option value="toplists/custom">GET /toplists/{id} (single — enter ID below)</option>
                                        <option value="brands">GET /brands (list all)</option>
                                        <option value="brands/custom">GET /brands/{id} (single — enter ID below)</option>
                                    </select>
                                </td>
                            </tr>
                            <tr id="df-preview-id-row" style="display:none;">
                                <th scope="row"><label for="df-preview-id">Resource ID</label></th>
                                <td><input type="number" id="df-preview-id" class="small-text" placeholder="42"></td>
                            </tr>
                            <tr>
                                <th scope="row"></th>
                                <td>
                                    <button type="button" id="df-preview-fetch" class="button button-primary">Fetch Preview</button>
                                    <span id="df-preview-status" style="margin-left:10px;"></span>
                                </td>
                            </tr>
                        </table>
                        </div><!-- /#df-single-panel -->
                        <!-- Compare mode panel -->
                        <div id="df-compare-panel" style="display:none;">
                            <table class="form-table">
                                <tr>
                                    <th scope="row">Endpoint</th>
                                    <td>
                                        <select id="df-compare-endpoint">
                                            <option value="brands">brands</option>
                                        </select>
                                        <button type="button" id="df-compare-run" class="button button-primary">Run Comparison</button>
                                        <span id="df-compare-status" style="margin-left:10px;"></span>
                                    </td>
                                </tr>
                            </table>
                            <div id="df-compare-result" style="display:none; margin-top:16px;">
                                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                                    <div>
                                        <strong id="df-v1-label" style="font-family:monospace;font-size:12px;color:#666;"></strong>
                                        <pre id="df-v1-json" style="background:#1e1e1e;color:#d4d4d4;padding:16px;border-radius:4px;max-height:400px;overflow:auto;font-size:11px;white-space:pre-wrap;word-break:break-all;margin:6px 0;"></pre>
                                        <button type="button" class="button button-secondary button-small df-copy-btn" data-target="df-v1-json">Copy V1</button>
                                    </div>
                                    <div>
                                        <strong id="df-v2-label" style="font-family:monospace;font-size:12px;color:#666;"></strong>
                                        <pre id="df-v2-json" style="background:#1e1e1e;color:#d4d4d4;padding:16px;border-radius:4px;max-height:400px;overflow:auto;font-size:11px;white-space:pre-wrap;word-break:break-all;margin:6px 0;"></pre>
                                        <button type="button" class="button button-secondary button-small df-copy-btn" data-target="df-v2-json">Copy V2</button>
                                    </div>
                                </div>
                                <div id="df-compare-diff" style="margin-top:12px; padding:12px; background:#f0f7ff; border-left:4px solid #0073aa; font-size:12px;"></div>
                            </div>
                        </div><!-- /#df-compare-panel -->

                        <div id="df-preview-result" style="display:none;margin-top:16px;">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                                <strong id="df-preview-url-label" style="font-family:monospace;font-size:12px;color:#666;"></strong>
                                <button type="button" id="df-preview-copy" class="button button-secondary button-small">Copy JSON</button>
                            </div>
                            <pre id="df-preview-json" style="
                                background:#1e1e1e;
                                color:#d4d4d4;
                                padding:16px;
                                border-radius:4px;
                                max-height:600px;
                                overflow:auto;
                                font-size:12px;
                                line-height:1.5;
                                white-space:pre-wrap;
                                word-break:break-all;
                            "></pre>
                        </div>

                        <script>
                        (function($){
                            var nonce = '<?php echo wp_create_nonce('dataflair_api_preview'); ?>';
                            var $endpoint = $('#df-preview-endpoint');
                            var $idRow    = $('#df-preview-id-row');
                            var $idInput  = $('#df-preview-id');
                            var $fetch    = $('#df-preview-fetch');
                            var $status   = $('#df-preview-status');
                            var $result   = $('#df-preview-result');
                            var $json     = $('#df-preview-json');
                            var $urlLabel = $('#df-preview-url-label');
                            var $copy     = $('#df-preview-copy');

                            $endpoint.on('change', function(){
                                var v = $(this).val();
                                $idRow.toggle(v === 'toplists/custom' || v === 'brands/custom');
                            });

                            $fetch.on('click', function(){
                                var ep = $endpoint.val();
                                var resourceId = $idInput.val().trim();

                                if ((ep === 'toplists/custom' || ep === 'brands/custom') && !resourceId) {
                                    alert('Please enter a resource ID.');
                                    return;
                                }

                                $fetch.prop('disabled', true);
                                $status.text('Fetching…');
                                $result.hide();

                                $.post(ajaxurl, {
                                    action:      'dataflair_api_preview',
                                    _ajax_nonce: nonce,
                                    endpoint:    ep,
                                    resource_id: resourceId
                                }, function(res){
                                    $fetch.prop('disabled', false);
                                    if (res.success) {
                                        var elapsed = res.data.elapsed ? '  ' + res.data.elapsed : '';
                                        $status.css('color','green').text('✔ ' + res.data.status + elapsed);
                                        $urlLabel.text(res.data.url);
                                        $json.text(res.data.body);
                                        $result.show();
                                    } else {
                                        $status.css('color','red').text('✖ ' + (res.data || 'Unknown error'));
                                    }
                                }).fail(function(){
                                    $fetch.prop('disabled', false);
                                    $status.css('color','red').text('✖ AJAX request failed');
                                });
                            });

                            $copy.on('click', function(){
                                var text = $json.text();
                                navigator.clipboard.writeText(text).then(function(){
                                    $copy.text('Copied!');
                                    setTimeout(function(){ $copy.text('Copy JSON'); }, 2000);
                                });
                            });

                            // Mode toggle: single vs compare
                            $('input[name="df-preview-mode"]').on('change', function(){
                                var mode = $(this).val();
                                if (mode === 'compare') {
                                    $('#df-single-panel').hide();
                                    $('#df-compare-panel').show();
                                } else {
                                    $('#df-single-panel').show();
                                    $('#df-compare-panel').hide();
                                }
                            });

                            // Copy buttons in compare panel
                            $(document).on('click', '.df-copy-btn', function(){
                                var targetId = $(this).data('target');
                                var text = $('#' + targetId).text();
                                var $btn = $(this);
                                navigator.clipboard.writeText(text).then(function(){
                                    $btn.text('Copied!');
                                    setTimeout(function(){ $btn.text($btn.data('target') === 'df-v1-json' ? 'Copy V1' : 'Copy V2'); }, 2000);
                                });
                            });

                            // Run comparison
                            $('#df-compare-run').on('click', function(){
                                var $btn = $(this);
                                var $status = $('#df-compare-status');
                                $btn.prop('disabled', true);
                                $status.text('Fetching V1…');
                                $('#df-compare-result').hide();

                                // Call V1 (brands)
                                $.post(ajaxurl, {
                                    action: 'dataflair_api_preview',
                                    _ajax_nonce: nonce,
                                    endpoint: 'brands'
                                }, function(v1res){
                                    if (!v1res.success) {
                                        $status.css('color','red').text('✖ V1 failed: ' + (v1res.data || 'error'));
                                        $btn.prop('disabled', false);
                                        return;
                                    }
                                    $status.text('Fetching V2…');

                                    // Call V2 (brands_v2)
                                    $.post(ajaxurl, {
                                        action: 'dataflair_api_preview',
                                        _ajax_nonce: nonce,
                                        endpoint: 'brands_v2'
                                    }, function(v2res){
                                        $btn.prop('disabled', false);
                                        if (!v2res.success) {
                                            $status.css('color','red').text('✖ V2 failed: ' + (v2res.data || 'error'));
                                            return;
                                        }

                                        // Display results
                                        var v1elapsed = v1res.data.elapsed ? '  ' + v1res.data.elapsed : '';
                                        var v2elapsed = v2res.data.elapsed ? '  ' + v2res.data.elapsed : '';
                                        $('#df-v1-label').text('GET ' + v1res.data.url + '  ✔ ' + v1res.data.status + v1elapsed);
                                        $('#df-v2-label').text('GET ' + v2res.data.url + '  ✔ ' + v2res.data.status + v2elapsed);
                                        $('#df-v1-json').text(v1res.data.body);
                                        $('#df-v2-json').text(v2res.data.body);

                                        // Compute field diff
                                        var diffHtml = '';
                                        try {
                                            var v1data = JSON.parse(v1res.data.body);
                                            var v2data = JSON.parse(v2res.data.body);
                                            var v1brand = (v1data.data && v1data.data[0]) ? v1data.data[0] : null;
                                            var v2brand = (v2data.data && v2data.data[0]) ? v2data.data[0] : null;
                                            if (v1brand && v2brand) {
                                                var v1keys = Object.keys(v1brand);
                                                var v2keys = Object.keys(v2brand);
                                                var brandOnlyV2 = v2keys.filter(function(k){ return v1keys.indexOf(k) === -1; });
                                                diffHtml += '<strong>Fields only in V2 (brand):</strong> ' + (brandOnlyV2.length ? brandOnlyV2.join(', ') : 'none') + '<br>';

                                                var v1offer = (v1brand.offers && v1brand.offers[0]) ? v1brand.offers[0] : null;
                                                var v2offer = (v2brand.offers && v2brand.offers[0]) ? v2brand.offers[0] : null;
                                                if (v1offer && v2offer) {
                                                    var v1offerKeys = Object.keys(v1offer);
                                                    var v2offerKeys = Object.keys(v2offer);
                                                    var offerOnlyV2 = v2offerKeys.filter(function(k){ return v1offerKeys.indexOf(k) === -1; });
                                                    diffHtml += '<strong>Fields only in V2 (offer):</strong> ' + (offerOnlyV2.length ? offerOnlyV2.join(', ') : 'none');
                                                }
                                            }
                                        } catch(e) {
                                            diffHtml = 'Could not compute diff: ' + e.message;
                                        }
                                        $('#df-compare-diff').html(diffHtml || 'No differences found.');
                                        $('#df-compare-result').show();
                                        $status.css('color','green').text('✔ Comparison complete');
                                    }).fail(function(){
                                        $btn.prop('disabled', false);
                                        $status.css('color','red').text('✖ V2 AJAX request failed');
                                    });
                                }).fail(function(){
                                    $btn.prop('disabled', false);
                                    $status.css('color','red').text('✖ V1 AJAX request failed');
                                });
                            });
                        })(jQuery);
                        </script>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </form>

            <style>
                /* Toplist Accordion Styles */
                .dataflair-toplists-table th.sortable-toplist { padding: 0; }
                .toplist-sort-link {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    padding: 8px 10px;
                    text-decoration: none;
                    color: inherit;
                    white-space: nowrap;
                }
                .toplist-sort-link:hover { background: #f0f0f1; }
                .toplist-sort-indicator { font-size: 10px; margin-left: 4px; color: #2271b1; }
                .dataflair-toplists-table tbody tr.toplist-row { transition: opacity 0.15s ease; }
                .toplist-toggle-btn {
                    background: none;
                    border: none;
                    cursor: pointer;
                    padding: 4px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    transition: all 0.2s;
                }
                .toplist-toggle-btn:hover {
                    background: #f0f0f1;
                    border-radius: 3px;
                }
                .toplist-toggle-btn .dashicons {
                    transition: transform 0.2s;
                }
                .toplist-accordion-inner {
                    animation: slideIn 0.2s ease-out;
                }
                @keyframes slideIn {
                    from {
                        opacity: 0;
                        transform: translateY(-10px);
                    }
                    to {
                        opacity: 1;
                        transform: translateY(0);
                    }
                }
            </style>
        </div>
        <?php
    }
}
