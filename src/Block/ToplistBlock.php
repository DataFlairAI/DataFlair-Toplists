<?php
/**
 * Phase 7 — ToplistBlock
 *
 * Single responsibility: server-side render callback for the
 * `dataflair-toplists/toplist` Gutenberg block.
 *
 * The block editor saves a flat $attributes bag — this class merges it with
 * settings-driven defaults, then delegates to the toplist shortcode renderer
 * (kept on the god-class until Phase 8 collapses every delegator). The
 * shortcode is passed as a `\Closure` so the block stays `$wpdb`-free and
 * tests can stub it with a trivial lambda.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Block;

final class ToplistBlock
{
    /** @var \Closure(array): string */
    private \Closure $shortcodeRenderer;

    /** @var \Closure(string, mixed=): mixed */
    private \Closure $optionReader;

    public function __construct(\Closure $shortcodeRenderer, \Closure $optionReader)
    {
        $this->shortcodeRenderer = $shortcodeRenderer;
        $this->optionReader      = $optionReader;
    }

    /**
     * WordPress render_callback entry point for register_block_type.
     *
     * @param array<string,mixed>|null $attributes
     */
    public function render($attributes): string
    {
        $attributes = is_array($attributes) ? $attributes : [];

        $defaults = $this->defaults();
        $atts     = wp_parse_args($attributes, $defaults);

        $toplist_id = $atts['toplistId'] ?? '';
        if ($toplist_id === '' || $toplist_id === null) {
            return '<p>' . esc_html__('Please configure the toplist ID in the block settings.', 'dataflair-toplists') . '</p>';
        }

        $shortcode_atts = [
            'id'                => $toplist_id,
            'title'             => $atts['title'],
            'limit'             => (int) $atts['limit'],
            'layout'            => $atts['layout'],
            'ribbonBgColor'     => $atts['ribbonBgColor'],
            'ribbonTextColor'   => $atts['ribbonTextColor'],
            'ribbonText'        => $atts['ribbonText'],
            'rankBgColor'       => $atts['rankBgColor'],
            'rankTextColor'     => $atts['rankTextColor'],
            'rankBorderRadius'  => $atts['rankBorderRadius'],
            'brandLinkColor'    => $atts['brandLinkColor'],
            'bonusLabelStyle'   => $atts['bonusLabelStyle'],
            'bonusTextStyle'    => $atts['bonusTextStyle'],
            'featureCheckBg'    => $atts['featureCheckBg'],
            'featureCheckColor' => $atts['featureCheckColor'],
            'featureTextColor'  => $atts['featureTextColor'],
            'ctaBgColor'        => $atts['ctaBgColor'],
            'ctaHoverBgColor'   => $atts['ctaHoverBgColor'],
            'ctaTextColor'      => $atts['ctaTextColor'],
            'ctaBorderRadius'   => $atts['ctaBorderRadius'],
            'ctaShadow'         => $atts['ctaShadow'],
            'metricLabelStyle'  => $atts['metricLabelStyle'],
            'metricValueStyle'  => $atts['metricValueStyle'],
            'rgBorderColor'     => $atts['rgBorderColor'],
            'rgTextColor'       => $atts['rgTextColor'],
            'prosCons'          => $attributes['prosCons'] ?? [],
        ];

        return (string) ($this->shortcodeRenderer)($shortcode_atts);
    }

    /**
     * Defaults sourced from plugin options (settings panel overrides).
     *
     * @return array<string,mixed>
     */
    private function defaults(): array
    {
        $ribbon_bg   = ($this->optionReader)('dataflair_ribbon_bg_color', 'brand-600');
        $ribbon_text = ($this->optionReader)('dataflair_ribbon_text_color', 'white');
        $cta_bg      = ($this->optionReader)('dataflair_cta_bg_color', 'brand-600');
        $cta_text    = ($this->optionReader)('dataflair_cta_text_color', 'white');

        return [
            'toplistId'         => '',
            'title'             => '',
            'limit'             => 0,
            'layout'            => 'cards',
            'ribbonBgColor'     => $ribbon_bg,
            'ribbonTextColor'   => $ribbon_text,
            'ribbonText'        => 'Our Top Choice',
            'rankBgColor'       => 'gray-100',
            'rankTextColor'     => 'gray-900',
            'rankBorderRadius'  => 'rounded',
            'brandLinkColor'    => 'brand-600',
            'bonusLabelStyle'   => 'text-gray-600',
            'bonusTextStyle'    => 'text-gray-900 text-lg leading-6 font-semibold',
            'featureCheckBg'    => 'green-100',
            'featureCheckColor' => 'green-600',
            'featureTextColor'  => 'gray-600',
            'ctaBgColor'        => $cta_bg,
            'ctaHoverBgColor'   => 'brand-700',
            'ctaTextColor'      => $cta_text,
            'ctaBorderRadius'   => 'rounded',
            'ctaShadow'         => 'shadow-md',
            'metricLabelStyle'  => 'text-gray-600',
            'metricValueStyle'  => 'text-gray-900 font-semibold',
            'rgBorderColor'     => 'gray-300',
            'rgTextColor'       => 'gray-600',
        ];
    }
}
