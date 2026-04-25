<?php
/**
 * Phase 9.6 — WP Settings API registration.
 *
 * Owns the `admin_init` hook and the `register_setting()` calls that
 * declare every option DataFlair stores under the `dataflair_settings`
 * group. Extracted from `DataFlair_Toplists::register_settings()`.
 *
 * Single responsibility: declare which options the plugin owns. Saving
 * those options is the SaveSettingsHandler's job (Phase 5). Reading them
 * is whatever code happens to need them.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin;

final class SettingsRegistrar
{
    /**
     * Option keys registered against the `dataflair_settings` group.
     *
     * Notes:
     *   - `dataflair_api_endpoints` is internal — populated by the
     *     fetch-all-toplists flow, not user-edited.
     *   - `dataflair_api_base_url` is registered idempotently (kept on
     *     the original double-call from the god-class for byte parity).
     */
    private const OPTIONS = [
        'dataflair_api_token',
        'dataflair_api_base_url',
        'dataflair_api_endpoints',
        'dataflair_api_base_url',
        'dataflair_http_auth_user',
        'dataflair_http_auth_pass',
        'dataflair_ribbon_bg_color',
        'dataflair_ribbon_text_color',
        'dataflair_cta_bg_color',
        'dataflair_cta_text_color',
    ];

    public function register(): void
    {
        add_action('admin_init', [$this, 'registerSettings']);
    }

    public function registerSettings(): void
    {
        $args = [
            'sanitize_callback' => null,
            'default'           => null,
        ];

        foreach (self::OPTIONS as $option) {
            register_setting('dataflair_settings', $option, $args);
        }
    }
}
