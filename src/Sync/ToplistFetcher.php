<?php
/**
 * Phase 9.10 — Single-toplist fetch + persist.
 *
 * Calls the toplist show-endpoint, validates the response, hands the
 * decoded payload + raw body off to ToplistDataStore for the actual
 * upsert. Side effects (`error_log`, `add_settings_error`) preserved
 * byte-for-byte from the v1.11.0 god-class.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Sync;

use DataFlair\Toplists\Database\ToplistDataStore;
use DataFlair\Toplists\Http\HttpClientInterface;

final class ToplistFetcher
{
    /** @var \Closure(int, string, array, string): string */
    private \Closure $errorBuilder;

    /**
     * @param \Closure(int $statusCode, string $body, array $headers, string $endpoint): string $errorBuilder
     *        Returns a human-readable error string. Today this is the
     *        god-class's `build_detailed_api_error()`; Phase 9.11
     *        replaces it with `Http\ApiErrorFormatter`.
     */
    public function __construct(
        private HttpClientInterface $httpClient,
        private ToplistDataStore $store,
        \Closure $errorBuilder
    ) {
        $this->errorBuilder = $errorBuilder;
    }

    public function fetchAndStore(string $endpoint, string $token): bool
    {
        $response = $this->httpClient->get($endpoint, $token);

        if (is_wp_error($response)) {
            $errorMessage = 'DataFlair API Error for ' . $endpoint . ': ' . $response->get_error_message();
            error_log($errorMessage);
            add_settings_error('dataflair_messages', 'dataflair_api_error', $errorMessage, 'error');
            return false;
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $responseHeaders = wp_remote_retrieve_headers($response);

        error_log('DataFlair API Response Code: ' . $statusCode . ' for endpoint: ' . $endpoint);

        if ($statusCode !== 200) {
            $errorMessage = ($this->errorBuilder)($statusCode, $body, $responseHeaders, $endpoint);
            error_log('DataFlair fetch_and_store_toplist error: ' . $errorMessage);
            add_settings_error('dataflair_messages', 'dataflair_api_error', $errorMessage, 'error');
            return false;
        }

        $responseData = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $errorMessage = 'DataFlair JSON Parse Error: ' . json_last_error_msg() . ' for ' . $endpoint;
            error_log($errorMessage);
            add_settings_error('dataflair_messages', 'dataflair_json_error', $errorMessage, 'error');
            return false;
        }

        if (!isset($responseData['data']['id'])) {
            $errorMessage = 'DataFlair API Error: Invalid response format for ' . $endpoint . '. Response: ' . substr($body, 0, 300);
            error_log($errorMessage);
            add_settings_error('dataflair_messages', 'dataflair_format_error', $errorMessage, 'error');
            return false;
        }

        return $this->store->store($responseData['data'], $body);
    }
}
