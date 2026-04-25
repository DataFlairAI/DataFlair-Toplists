<?php
/**
 * Phase 9.10 — Toplist endpoint discovery.
 *
 * The DataFlair v2 API paginates `/toplists` (default 15 per page).
 * Sync drivers need the full list of show-endpoints to fan out into
 * per-toplist fetches; this class walks every page and returns the
 * collected endpoint URLs.
 *
 * Returns an empty array on any error — the sync drivers fall back to
 * the cached `dataflair_api_endpoints` option in that case.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Sync;

use DataFlair\Toplists\Http\HttpClientInterface;

final class EndpointDiscovery
{
    /** @var \Closure(): string */
    private \Closure $apiBaseUrlResolver;

    public function __construct(
        private HttpClientInterface $httpClient,
        \Closure $apiBaseUrlResolver
    ) {
        $this->apiBaseUrlResolver = $apiBaseUrlResolver;
    }

    /**
     * Walk `/toplists?per_page=15&page=N` until the meta says we're done.
     *
     * @return string[] Endpoint URLs of the form `<base>/toplists/<id>`.
     */
    public function discover(string $token): array
    {
        $baseUrl = ($this->apiBaseUrlResolver)();
        $endpoints = [];
        $currentPage = 1;
        $lastPage = 1;

        do {
            $listUrl = $baseUrl . '/toplists?per_page=15&page=' . $currentPage;
            $response = $this->httpClient->get($listUrl, $token);

            if (is_wp_error($response)) {
                error_log('DataFlair discover_toplist_endpoints error (page ' . $currentPage . '): ' . $response->get_error_message());
                break;
            }

            $statusCode = wp_remote_retrieve_response_code($response);
            if ($statusCode !== 200) {
                error_log('DataFlair discover_toplist_endpoints: HTTP ' . $statusCode . ' on page ' . $currentPage);
                break;
            }

            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (json_last_error() !== JSON_ERROR_NONE || !isset($data['data'])) {
                error_log('DataFlair discover_toplist_endpoints: invalid JSON on page ' . $currentPage);
                break;
            }

            foreach ($data['data'] as $toplist) {
                if (isset($toplist['id'])) {
                    $endpoints[] = $baseUrl . '/toplists/' . $toplist['id'];
                }
            }

            if (isset($data['meta']['last_page'])) {
                $lastPage = (int) $data['meta']['last_page'];
            }

            $currentPage++;
        } while ($currentPage <= $lastPage);

        return $endpoints;
    }
}
