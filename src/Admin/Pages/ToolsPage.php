<?php
/**
 * Phase 9.6 (admin UX redesign) — Tools page.
 *
 * Three tabs:
 *   tests       — Diagnostic test suite via TestsRunner; results persist in
 *                 dataflair_test_results option. No auto-run on load.
 *   api_preview — API Response Preview (verbatim from Phase 1)
 *   logs        — Debug Log viewer (tail + download, filtered by [DataFlair])
 *
 * Phase 3 changes:
 *   - renderTestsTab() replaced: no longer calls legacy tests_page(); now uses
 *     TestsRunner-driven UI (status bar, test cards, Run / Run all via AJAX).
 *   - renderLogsTab() replaced: real log viewer (AJAX tail + severity colouring,
 *     Download button).
 *   - tools.js wired in by AdminAssetsRegistrar for this page's hook.
 *
 * Backward compat: `?page=dataflair-toplists&tab=api_preview` redirects here
 * via MenuRegistrar::addBackwardCompatRedirects().
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Pages;

use DataFlair\Toplists\Admin\Pages\Tools\TestsRunner;

final class ToolsPage implements PageInterface
{
    public function __construct(
        private \Closure $apiBaseUrlResolver
    ) {}

    public function render(): void
    {
        $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'tests';
        ?>
        <div class="wrap">
            <div class="df-page-header">
                <h1 class="df-page-header__title">Tools</h1>
            </div>

            <nav class="nav-tab-wrapper">
                <a href="?page=dataflair-tools&tab=tests"
                   class="nav-tab <?php echo $current_tab === 'tests' ? 'nav-tab-active' : ''; ?>">
                    Tests &amp; Diagnostics
                </a>
                <a href="?page=dataflair-tools&tab=api_preview"
                   class="nav-tab <?php echo $current_tab === 'api_preview' ? 'nav-tab-active' : ''; ?>">
                    API Preview
                </a>
                <a href="?page=dataflair-tools&tab=logs"
                   class="nav-tab <?php echo $current_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
                    Logs
                </a>
            </nav>
            <script>
            window.DFTools = window.DFTools || {};
            DFTools.ajaxUrl   = <?php echo json_encode(admin_url('admin-ajax.php')); ?>;
            DFTools.nonces    = {
                runTest:      <?php echo json_encode(wp_create_nonce('dataflair_run_test')); ?>,
                runAll:       <?php echo json_encode(wp_create_nonce('dataflair_run_all_tests')); ?>,
                logsTail:     <?php echo json_encode(wp_create_nonce('dataflair_logs_tail')); ?>,
                logsDownload: <?php echo json_encode(wp_create_nonce('dataflair_logs_download')); ?>,
            };
            </script>

            <?php if ($current_tab === 'tests'): ?>
                <?php $this->renderTestsTab(); ?>
            <?php elseif ($current_tab === 'api_preview'): ?>
                <?php $this->renderApiPreviewTab(); ?>
            <?php else: ?>
                <?php $this->renderLogsTab(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    // ── Tests tab ────────────────────────────────────────────────────────────

    private function renderTestsTab(): void
    {
        $runner  = new TestsRunner();
        $results = $runner->loadAll();
        $registry = TestsRunner::registry();

        $pass = $fail = $warn = $pending = 0;
        $last_runs = [];
        foreach ($results as $slug => $r) {
            match ($r['status']) {
                'pass'    => $pass++,
                'fail'    => $fail++,
                'warn'    => $warn++,
                default   => $pending++,
            };
            if ($r['last_run_iso']) {
                $last_runs[] = strtotime($r['last_run_iso']);
            }
        }
        $total         = count($registry);
        $last_full_run = !empty($last_runs) ? max($last_runs) : 0;
        $last_run_label = $last_full_run ? human_time_diff($last_full_run) . ' ago' : 'never';
        ?>
        <div class="tab-content" style="padding-top:16px;">

            <!-- Status bar -->
            <div style="display:flex;align-items:center;justify-content:space-between;
                        background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;
                        padding:12px 16px;margin-bottom:16px;gap:16px;flex-wrap:wrap;">
                <span style="font-size:13px;color:#3c434a;">
                    <?php echo esc_html($total); ?> tests
                    &bull; last full run:
                    <strong id="df-tests-last-run"><?php echo esc_html($last_run_label); ?></strong>
                    <?php if ($fail > 0): ?>
                        &bull; <strong style="color:#d63638;" id="df-tests-failing-count">
                            <?php echo esc_html($fail); ?> failing
                        </strong>
                    <?php elseif ($pass === $total): ?>
                        &bull; <strong style="color:#00a32a;">all passing</strong>
                    <?php endif; ?>
                </span>
                <button type="button" id="df-run-all-btn" class="button button-secondary">
                    ▶ Run all
                </button>
            </div>

            <!-- Test list -->
            <table class="wp-list-table widefat striped" id="df-tests-table">
                <thead>
                    <tr>
                        <th style="width:22%;">Test</th>
                        <th>Description</th>
                        <th style="width:10%;">Status</th>
                        <th style="width:15%;">Message</th>
                        <th style="width:12%;">Last Run</th>
                        <th style="width:8%;">Duration</th>
                        <th style="width:6%;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($registry as $slug => $meta): ?>
                        <?php
                        $r   = $results[$slug];
                        $iso = $r['last_run_iso'] ? esc_attr($r['last_run_iso']) : '';
                        $ts  = $iso ? human_time_diff((int) strtotime($iso)) . ' ago' : '—';
                        $ms  = $r['duration_ms'] ? $r['duration_ms'] . ' ms' : '—';
                        ?>
                        <tr id="df-test-row-<?php echo esc_attr($slug); ?>"
                            data-slug="<?php echo esc_attr($slug); ?>">
                            <td>
                                <strong><?php echo esc_html($meta['label']); ?></strong>
                                <br><code style="font-size:11px;color:#646970;"><?php echo esc_html($slug); ?></code>
                            </td>
                            <td style="color:#646970;font-size:12px;"><?php echo esc_html($meta['description']); ?></td>
                            <td class="df-test-status-cell">
                                <?php $this->renderStatusPill($r['status']); ?>
                            </td>
                            <td class="df-test-message-cell" style="font-size:12px;color:#3c434a;">
                                <?php echo esc_html($r['message']); ?>
                            </td>
                            <td class="df-test-lastrun-cell" style="font-size:12px;color:#646970;">
                                <?php echo esc_html($ts); ?>
                            </td>
                            <td class="df-test-duration-cell" style="font-size:12px;color:#646970;">
                                <?php echo esc_html($ms); ?>
                            </td>
                            <td>
                                <button type="button"
                                        class="button button-small df-run-test-btn"
                                        data-slug="<?php echo esc_attr($slug); ?>">
                                    ▶
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        </div>
        <?php
    }

    private function renderStatusPill(string $status): void
    {
        $map = [
            'pass'    => ['class' => 'df-pill--success',  'label' => 'Pass'],
            'fail'    => ['class' => 'df-pill--error',    'label' => 'Fail'],
            'warn'    => ['class' => 'df-pill--warning',  'label' => 'Warn'],
            'pending' => ['class' => 'df-pill--gray',     'label' => 'Pending'],
        ];
        $entry = $map[$status] ?? $map['pending'];
        echo '<span class="df-pill ' . esc_attr($entry['class']) . '">' . esc_html($entry['label']) . '</span>';
    }

    // ── Logs tab ─────────────────────────────────────────────────────────────

    private function renderLogsTab(): void
    {
        ?>
        <div class="tab-content" style="padding-top:16px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                <div>
                    <h2 style="margin:0 0 4px;">Debug Log</h2>
                    <p class="description" style="margin:0;">
                        Recent plugin log entries from <code>wp-content/debug.log</code>
                        filtered by the <code>[DataFlair]</code> prefix. Severity colouring:
                        <span style="color:#d63638;">error</span> ·
                        <span style="color:#dba617;">warning</span> ·
                        <span style="color:#1d2327;">info/debug</span>.
                    </p>
                </div>
                <div style="display:flex;gap:8px;flex-shrink:0;">
                    <button type="button" id="df-logs-refresh" class="button">⟳ Load Logs</button>
                    <a id="df-logs-download" href="#" class="button" style="text-decoration:none;">↓ Download</a>
                </div>
            </div>
            <div id="df-logs-status" style="color:#646970;font-size:13px;margin-bottom:8px;"></div>
            <div id="df-logs-container"
                 style="background:#1e1e1e;border-radius:4px;padding:16px;max-height:520px;
                        overflow-y:auto;font-family:monospace;font-size:12px;line-height:1.6;">
                <span style="color:#646970;">Click "Load Logs" to fetch recent DataFlair log entries.</span>
            </div>
        </div>
        <?php
    }

    // ── API Preview tab ──────────────────────────────────────────────────────

    private function renderApiPreviewTab(): void
    {
        $token    = trim(get_option('dataflair_api_token', ''));
        $base_url = ($this->apiBaseUrlResolver)();
        ?>
        <div class="tab-content" style="padding-top:16px;">
            <h2>API Response Preview</h2>
            <p class="description">Fetch a live response from the DataFlair API using your stored token. Select an endpoint and click <strong>Fetch Preview</strong> to inspect the raw JSON.</p>

            <?php if (empty($token)): ?>
                <div class="notice notice-warning inline"><p>No API token configured. Set your token on the <a href="?page=dataflair-settings&tab=api_connection">API Settings</a> tab first.</p></div>
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
                            <option value="brands_v2">GET /brands — V2 (list all, V2 schema)</option>
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
                <pre id="df-preview-json" style="background:#1e1e1e;color:#d4d4d4;padding:16px;border-radius:4px;max-height:600px;overflow:auto;font-size:12px;line-height:1.5;white-space:pre-wrap;word-break:break-all;"></pre>
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
                    $idRow.toggle(v === 'toplists/custom');
                });

                $fetch.on('click', function(){
                    var ep = $endpoint.val();
                    var resourceId = $idInput.val().trim();
                    if (ep === 'toplists/custom' && !resourceId) {
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

                $(document).on('click', '.df-copy-btn', function(){
                    var targetId = $(this).data('target');
                    var text = $('#' + targetId).text();
                    var $btn = $(this);
                    navigator.clipboard.writeText(text).then(function(){
                        $btn.text('Copied!');
                        setTimeout(function(){ $btn.text($btn.data('target') === 'df-v1-json' ? 'Copy V1' : 'Copy V2'); }, 2000);
                    });
                });

                $('#df-compare-run').on('click', function(){
                    var $btn = $(this);
                    var $status = $('#df-compare-status');
                    $btn.prop('disabled', true);
                    $status.text('Fetching V1…');
                    $('#df-compare-result').hide();
                    $.post(ajaxurl, {
                        action: 'dataflair_api_preview', _ajax_nonce: nonce, endpoint: 'brands'
                    }, function(v1res){
                        if (!v1res.success) {
                            $status.css('color','red').text('✖ V1 failed: ' + (v1res.data || 'error'));
                            $btn.prop('disabled', false);
                            return;
                        }
                        $status.text('Fetching V2…');
                        $.post(ajaxurl, {
                            action: 'dataflair_api_preview', _ajax_nonce: nonce, endpoint: 'brands_v2'
                        }, function(v2res){
                            $btn.prop('disabled', false);
                            if (!v2res.success) {
                                $status.css('color','red').text('✖ V2 failed: ' + (v2res.data || 'error'));
                                return;
                            }
                            var v1elapsed = v1res.data.elapsed ? '  ' + v1res.data.elapsed : '';
                            var v2elapsed = v2res.data.elapsed ? '  ' + v2res.data.elapsed : '';
                            $('#df-v1-label').text('GET ' + v1res.data.url + '  ✔ ' + v1res.data.status + v1elapsed);
                            $('#df-v2-label').text('GET ' + v2res.data.url + '  ✔ ' + v2res.data.status + v2elapsed);
                            $('#df-v1-json').text(v1res.data.body);
                            $('#df-v2-json').text(v2res.data.body);
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
                            } catch(e) { diffHtml = 'Could not compute diff: ' + e.message; }
                            $('#df-compare-diff').html(diffHtml || 'No differences found.');
                            $('#df-compare-result').show();
                            $status.css('color','green').text('✔ Comparison complete');
                        }).fail(function(){ $btn.prop('disabled', false); $status.css('color','red').text('✖ V2 AJAX request failed'); });
                    }).fail(function(){ $btn.prop('disabled', false); $status.css('color','red').text('✖ V1 AJAX request failed'); });
                });
            })(jQuery);
            </script>
            <?php endif; ?>
        </div>
        <?php
    }
}
