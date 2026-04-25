<?php
/**
 * Phase 9.12 — `/go/?campaign=…` affiliate redirect handler.
 *
 * Pulled out of the god-class `handle_campaign_redirect()`. Behaviour is
 * byte-identical to v2.1.7:
 *
 *   - Hooks `template_redirect`.
 *   - Returns silently when the request is not a `/go` path or when no
 *     campaign query var is present.
 *   - Looks up the tracker URL via the `dataflair_tracker_<md5>` transient.
 *   - Returns 404 (`status_header(404)` + `nocache_headers()`) when the
 *     campaign is missing/empty or the stored URL fails `FILTER_VALIDATE_URL`.
 *   - Issues a 301 `wp_redirect()` to the validated tracker URL on hit.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Frontend\Redirect;

final class CampaignRedirectHandler
{
    public function register(): void
    {
        if (!function_exists('add_action')) {
            return;
        }
        add_action('template_redirect', [$this, 'handle']);
    }

    public function handle(): void
    {
        if (!isset($_GET['campaign']) || empty($_GET['campaign'])) {
            return;
        }

        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $parsed_url  = parse_url($request_uri);
        $path        = isset($parsed_url['path']) ? (string) $parsed_url['path'] : '';

        if (strpos($path, '/go') === false) {
            return;
        }

        $campaign_name = sanitize_text_field((string) $_GET['campaign']);

        if (empty($campaign_name)) {
            status_header(404);
            nocache_headers();
            return;
        }

        $transient_key = 'dataflair_tracker_' . md5($campaign_name);
        $tracker_url   = get_transient($transient_key);

        if (empty($tracker_url) || !filter_var($tracker_url, FILTER_VALIDATE_URL)) {
            status_header(404);
            nocache_headers();
            return;
        }

        wp_redirect($tracker_url, 301);
        exit;
    }
}
