<?php
/**
 * Dev-preview endpoint: fetch a live upstream API response and render it as
 * pretty JSON for inspection in the admin Tools tab. No credentials are ever
 * returned to the browser — the handler owns the token server-side and only
 * exposes the final URL, HTTP status, JSON body, and elapsed ms.
 *
 * Endpoint keys supported: toplists, toplists/custom (needs resource_id),
 * brands, brands/custom (needs resource_id), brands_v2 (rewrites /api/vN → v2).
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Ajax;

use DataFlair\Toplists\Admin\AjaxHandlerInterface;
use DataFlair\Toplists\Http\HttpClientInterface;

final class ApiPreviewHandler implements AjaxHandlerInterface
{
    public function __construct(
        private HttpClientInterface $client,
        private \Closure $baseUrlResolver
    ) {}

    public function handle(array $request): array
    {
        $token = trim((string) get_option('dataflair_api_token', ''));
        if ($token === '') {
            return ['success' => false, 'data' => ['message' => 'No API token configured.']];
        }

        $endpoint_key = isset($request['endpoint']) ? sanitize_text_field((string) $request['endpoint']) : '';
        $resource_id  = isset($request['resource_id']) ? absint((string) $request['resource_id']) : 0;

        $base_url = rtrim((string) ($this->baseUrlResolver)(), '/');
        $url      = $this->resolveUrl($endpoint_key, $resource_id, $base_url);

        if ($url === null) {
            return ['success' => false, 'data' => ['message' => $resource_id === 0 && in_array($endpoint_key, ['toplists/custom', 'brands/custom'], true)
                ? 'Resource ID required for single ' . explode('/', $endpoint_key)[0] . '.'
                : 'Unknown endpoint.']];
        }

        $start    = microtime(true);
        $response = $this->client->get($url, $token);

        if (is_wp_error($response)) {
            return ['success' => false, 'data' => ['message' => $response->get_error_message()]];
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $raw_body    = (string) wp_remote_retrieve_body($response);
        $decoded     = json_decode($raw_body, true);
        $pretty      = ($decoded !== null)
            ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : $raw_body;

        return ['success' => true, 'data' => [
            'url'     => $url,
            'status'  => $status_code . ' ' . (function_exists('get_status_header_desc') ? get_status_header_desc($status_code) : ''),
            'body'    => $pretty,
            'elapsed' => round((microtime(true) - $start) * 1000) . 'ms',
        ]];
    }

    private function resolveUrl(string $endpoint_key, int $resource_id, string $base_url): ?string
    {
        switch ($endpoint_key) {
            case 'toplists':
                return $base_url . '/toplists';
            case 'toplists/custom':
                return $resource_id > 0 ? $base_url . '/toplists/' . $resource_id : null;
            case 'brands':
                return $base_url . '/brands';
            case 'brands/custom':
                return $resource_id > 0 ? $base_url . '/brands/' . $resource_id : null;
            case 'brands_v2':
                $v2_base = preg_replace('#/api/v\d+$#', '/api/v2', $base_url);
                return rtrim((string) $v2_base, '/') . '/brands';
            default:
                return null;
        }
    }
}
