/* DataFlair Admin — Color picker / live preview wiring. Phase 9.6.
 *
 * Attaches to any element with data-df-swatch="<target-input-id>".
 * Keeps the swatch background in sync with the text input value.
 * Also updates the live preview card on input.
 *
 * Live preview targets (all optional; only wired if found in DOM):
 *   #df-preview-ribbon-bg    → ribbon background color
 *   #df-preview-ribbon-text  → ribbon text color
 *   #df-preview-cta-bg       → CTA button background
 *   #df-preview-cta-text     → CTA button text color
 */
(function ($) {
    'use strict';

    var colorMap = {
        dataflair_ribbon_bg_color:   '#df-preview-ribbon-bg',
        dataflair_ribbon_text_color: '#df-preview-ribbon-text',
        dataflair_cta_bg_color:      '#df-preview-cta-bg',
        dataflair_cta_text_color:    '#df-preview-cta-text',
    };

    function isCssColor(v) {
        // Accept hex colors for live preview; Tailwind class names are passed as-is (no swatch)
        return /^#[0-9a-fA-F]{3,8}$/.test(v) || /^rgba?\(/.test(v);
    }

    function syncSwatch($input) {
        var val   = $input.val().trim();
        var $sw   = $('[data-df-swatch="' + $input.attr('id') + '"]');
        if ($sw.length && isCssColor(val)) {
            $sw.css('background-color', val);
        }
    }

    function syncPreview($input) {
        var val    = $input.val().trim();
        var target = colorMap[$input.attr('name')];
        if (!target) { return; }
        var $el = $(target);
        if (!$el.length) { return; }
        if (isCssColor(val)) {
            $el.css($input.attr('name').endsWith('_text_color') ? 'color' : 'background-color', val);
        }
    }

    $(document).ready(function () {
        // Initial sync
        $.each(colorMap, function (name) {
            var $input = $('[name="' + name + '"]');
            if ($input.length) {
                syncSwatch($input);
                syncPreview($input);
            }
        });

        // Live updates
        $(document).on('input change', '[name^="dataflair_ribbon_"], [name^="dataflair_cta_"]', function () {
            syncSwatch($(this));
            syncPreview($(this));
            if (window.DFAdmin && window.DFAdmin.dirtyState) {
                // dirtyState handles its own check via form events
            }
        });
    });

}(jQuery));
