/**
 * Phase 9.6 (admin UX redesign) — shared admin chrome runtime.
 *
 * Exposes a tiny global API at window.DFAdmin used by every admin page:
 *   DFAdmin.toast(type, msg, ttl?)  — push a toast into the global region
 *   DFAdmin.confirm(msg)            — Promise<bool>, wraps native confirm
 *   DFAdmin.ajax(action, data, nonceKey)
 *                                   — POST to admin-ajax.php with nonce
 *
 * Loaded on every dataflair_page_* hook by AdminAssetsRegistrar. Vanilla JS,
 * no jQuery, no build step.
 */
(function () {
    'use strict';

    var ROOT_ID = 'df-toast-region';

    function ensureRegion() {
        var el = document.getElementById(ROOT_ID);
        if (el) return el;
        el = document.createElement('div');
        el.id = ROOT_ID;
        el.className = 'df-toast-region';
        el.setAttribute('role', 'status');
        el.setAttribute('aria-live', 'polite');
        document.body.appendChild(el);
        return el;
    }

    function toast(type, msg, ttl) {
        var region = ensureRegion();
        var node = document.createElement('div');
        node.className = 'df-toast df-toast--' + (type || 'info');
        node.textContent = String(msg == null ? '' : msg);
        region.appendChild(node);
        var ms = typeof ttl === 'number' ? ttl : 4500;
        setTimeout(function () {
            node.style.transition = 'opacity 200ms';
            node.style.opacity = '0';
            setTimeout(function () { node.remove(); }, 220);
        }, ms);
    }

    function confirmAsync(msg) {
        return Promise.resolve(window.confirm(String(msg)));
    }

    function ajax(action, data, nonceKey) {
        var cfg = window.dataflairAdmin || {};
        var url = cfg.ajaxUrl || (window.ajaxurl || '/wp-admin/admin-ajax.php');
        var nonce = nonceKey && cfg[nonceKey] ? cfg[nonceKey] : (cfg.nonce || '');
        var body = new URLSearchParams();
        body.append('action', action);
        body.append('nonce', nonce);
        Object.keys(data || {}).forEach(function (k) {
            var v = data[k];
            if (Array.isArray(v)) {
                v.forEach(function (item) { body.append(k + '[]', item); });
            } else if (v !== undefined && v !== null) {
                body.append(k, v);
            }
        });
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        }).then(function (r) { return r.json(); });
    }

    window.DFAdmin = {
        toast: toast,
        confirm: confirmAsync,
        ajax: ajax,
    };

    // Surface PHP-side admin notices from add_settings_error() as toasts when
    // pages opt in by rendering <div class="df-flash" data-type=… data-msg=…>.
    document.addEventListener('DOMContentLoaded', function () {
        var flashes = document.querySelectorAll('.df-flash');
        flashes.forEach(function (n) {
            toast(n.getAttribute('data-type') || 'info', n.getAttribute('data-msg') || '');
            n.remove();
        });
    });
})();
