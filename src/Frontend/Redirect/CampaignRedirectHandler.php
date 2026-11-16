<?php
/**
 * The plugin's legacy /go campaign redirect feature is disabled.
 * Existing cached tracker data is preserved, but is never used by this route.
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
        // Stop before WordPress's canonical redirect can guess another page.
        add_action('template_redirect', [$this, 'handle'], 0);
    }

    public function handle(): void
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $goPath = parse_url(home_url('/go/'), PHP_URL_PATH);

        if (!is_string($path) || !is_string($goPath)
            || rtrim($path, '/') !== rtrim($goPath, '/')) {
            return;
        }

        nocache_headers();
        wp_die(
            'This redirect feature has been disabled.',
            'Redirect unavailable',
            ['response' => 410]
        );
    }
}
