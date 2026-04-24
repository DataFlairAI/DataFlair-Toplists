<?php
/**
 * Persist the DataFlair settings-page options.
 *
 * Migrated from DataFlair_Toplists::ajax_save_settings() (v1.13.0). Every
 * field's sanitisation rule is preserved byte-for-byte: token + HTTP basic
 * auth password are trimmed but not `sanitize_text_field`'d (they may carry
 * characters that would be mangled); base URL is `esc_url_raw`'d + pinned to
 * /api/vN; colour + brands-API-version fields pass through
 * `sanitize_text_field`; brands-API version is whitelisted to v1|v2.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Handlers;

use DataFlair\Toplists\Admin\AjaxHandlerInterface;

final class SaveSettingsHandler implements AjaxHandlerInterface
{
    public function handle(array $request): array
    {
        if (isset($request['dataflair_api_token'])) {
            update_option('dataflair_api_token', trim((string) $request['dataflair_api_token']));
        }

        if (isset($request['dataflair_api_base_url'])) {
            $base_url = trim((string) $request['dataflair_api_base_url']);
            if ($base_url !== '') {
                $base_url = rtrim(esc_url_raw($base_url), '/');
                $base_url = preg_replace('#(/api/v\d+)/.*$#', '$1', $base_url);
                update_option('dataflair_api_base_url', $base_url);
            } else {
                delete_option('dataflair_api_base_url');
            }
        }

        if (isset($request['dataflair_http_auth_user'])) {
            update_option('dataflair_http_auth_user', sanitize_text_field((string) $request['dataflair_http_auth_user']));
        }
        if (isset($request['dataflair_http_auth_pass'])) {
            update_option('dataflair_http_auth_pass', trim((string) $request['dataflair_http_auth_pass']));
        }

        $version = (isset($request['dataflair_brands_api_version'])
            && $request['dataflair_brands_api_version'] === 'v2') ? 'v2' : 'v1';
        update_option('dataflair_brands_api_version', $version);

        foreach (['dataflair_ribbon_bg_color', 'dataflair_ribbon_text_color', 'dataflair_cta_bg_color', 'dataflair_cta_text_color'] as $key) {
            if (isset($request[$key])) {
                update_option($key, sanitize_text_field((string) $request[$key]));
            }
        }

        return ['success' => true, 'data' => ['message' => 'Settings saved successfully.']];
    }
}
