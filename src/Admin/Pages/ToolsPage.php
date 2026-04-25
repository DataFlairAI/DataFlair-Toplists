<?php
/**
 * Phase 9.6 (admin UX redesign) — Tools page.
 *
 * Three tabs:
 *   tests      — Tests & Diagnostics (drop WP_DEBUG gate; Phase 3 adds persistence)
 *   api_preview — API Response Preview (verbatim from SettingsPage)
 *   logs        — Debug Log viewer (Phase 3 adds real tail; placeholder for now)
 *
 * Backward compat: `?page=dataflair-toplists&tab=api_preview` redirects here
 * via `MenuRegistrar::addBackwardCompatRedirects()`.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Pages;

final class ToolsPage implements PageInterface
{
    public function __construct(
        private \Closure $apiBaseUrlResolver,
        private \DataFlair_Toplists $legacy
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
                <a href="?page=dataflair-tools&tab=tests" class="nav-tab <?php echo $current_tab === 'tests' ? 'nav-tab-active' : ''; ?>">
                    Tests &amp; Diagnostics
                </a>
                <a href="?page=dataflair-tools&tab=api_preview" class="nav-tab <?php echo $current_tab === 'api_preview' ? 'nav-tab-active' : ''; ?>">
                    API Preview
                </a>
                <a href="?page=dataflair-tools&tab=logs" class="nav-tab <?php echo $current_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
                    Logs
                </a>
            </nav>

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

    private function renderTestsTab(): void
    {
        $this->legacy->tests_page();
    }

    private function renderLogsTab(): void
    {
        ?>
        <div class="tab-content" style="padding-top:16px;">
            <h2>Debug Log</h2>
            <p class="description">
                Displays recent plugin log entries from <code>wp-content/debug.log</code> filtered
                by the <code>[DataFlair]</code> prefix. Full log viewer (tail, download, severity
                colouring) is coming in a subsequent release.
            </p>
            <p class="description" style="color:#646970;">
                Enable <code>WP_DEBUG_LOG</code> in <code>wp-config.php</code> to capture log output.
            </p>
        </div>
        <?php
    }

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
