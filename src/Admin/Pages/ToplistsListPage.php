<?php
/**
 * Admin page that lists all synced toplists and provides the "Fetch All
 * Toplists from API" trigger. Extracted from SettingsPage so each page class
 * has a single responsibility.
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
        $toplists = $wpdb->get_results(
            "SELECT id, api_toplist_id, name, slug, version,
                    last_synced, item_count, locked_count, sync_warnings,
                    current_period,
                    JSON_UNQUOTE(JSON_EXTRACT(data, '$.data.template.name')) AS template_name
             FROM $table_name"
        );
        if (is_array($toplists)) {
            usort($toplists, static fn($a, $b) => (int) $a->api_toplist_id <=> (int) $b->api_toplist_id);
        }
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
                    $tname = isset($tl->template_name) ? (string) $tl->template_name : '';
                    if ($tname !== '') $all_templates[] = $tname;
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
                            $template_name = isset($toplist->template_name) ? (string) $toplist->template_name : '';
                            $items_count  = isset($toplist->item_count)   ? (int) $toplist->item_count   : 0;
                            $locked_count = isset($toplist->locked_count) ? (int) $toplist->locked_count : 0;
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

            <style>
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
                    from { opacity: 0; transform: translateY(-10px); }
                    to   { opacity: 1; transform: translateY(0); }
                }
            </style>
        </div>
        <?php
    }
}
