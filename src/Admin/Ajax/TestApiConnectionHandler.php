<?php
/**
 * Phase 9.6 (admin UX redesign) — Test the configured API connection.
 *
 * Issues a live GET to `{base_url}/toplists` with the stored Bearer token
 * and returns status_code + round-trip ms. Used by the "Test connection"
 * button on the Settings → API Connection tab. Result is NOT cached.
 *
 * Output: { status_code: int, ms: int, error: string }
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Ajax;

use DataFlair\Toplists\Admin\AjaxHandlerInterface;
use DataFlair\Toplists\Http\ApiBaseUrlDetector;
use DataFlair\Toplists\Support\UrlTransformer;

final class TestApiConnectionHandler implements AjaxHandlerInterface
{
    public function __construct(private ApiBaseUrlDetector $base)
    {
    }

    public function handle(array $request): array
    {
        $token = trim((string) get_option('dataflair_api_token', ''));

        if ($token === '') {
            return ['success' => false, 'data' => ['message' => 'API token is not configured.']];
        }
        if (! $this->base->isConfigured()) {
            return ['success' => false, 'data' => ['message' => 'API base URL is not configured.']];
        }

        // Toplists always use v1, regardless of the Brands API Version radio.
        $base_url = UrlTransformer::withApiVersion($this->base->detect(), 'v1');
        $endpoint = $base_url . '/toplists';
        $start    = microtime(true);
        $resp     = wp_remote_get($endpoint, [
            'timeout' => 5,
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'sslverify' => false,
        ]);
        $ms = (int) round((microtime(true) - $start) * 1000);

        if (is_wp_error($resp)) {
            return ['success' => true, 'data' => [
                'status_code' => 0,
                'ms'          => $ms,
                'error'       => $resp->get_error_message(),
            ]];
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        return ['success' => true, 'data' => [
            'status_code' => $code,
            'ms'          => $ms,
            'error'       => ($code < 200 || $code >= 400) ? "HTTP {$code}" : '',
        ]];
    }
}
