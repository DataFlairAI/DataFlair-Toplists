<?php
/**
 * Phase 9.11 — Human-readable API error formatter.
 *
 * Distinguishes HTTP Basic Auth 401 (web-server gate) from API Bearer
 * 401, plus tailored guidance for 403/404/419/429/500/502-504. Returns
 * a single string suitable for surfacing in the admin UI or logs.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Http;

final class ApiErrorFormatter
{
    /**
     * @param int          $statusCode HTTP status code from the failed response.
     * @param string       $body       Raw response body (may be JSON, HTML, or plain text).
     * @param array|object $headers    Header bag in either WP `Requests_Utility_CaseInsensitiveDictionary` or array form.
     * @param string       $url        The URL that was requested.
     */
    public function format(int $statusCode, string $body, $headers, string $url): string
    {
        $parsed = parse_url($url);
        $host   = isset($parsed['host']) ? $parsed['host'] : 'unknown';

        $apiMessage = '';
        $json       = json_decode($body, true);
        if (is_array($json) && isset($json['message'])) {
            $apiMessage = (string) $json['message'];
        }

        $wwwAuth      = $this->headerValue($headers, 'www-authenticate');
        $contentType  = $this->headerValue($headers, 'content-type');
        $isHtmlReply  = (stripos($contentType, 'text/html') !== false);

        switch ($statusCode) {
            case 401:
                if (stripos($wwwAuth, 'Basic') !== false) {
                    $hasHttpAuth = ! empty(trim((string) get_option('dataflair_http_auth_user', '')));
                    if ($hasHttpAuth) {
                        return 'HTTP Basic Auth failed (401). Your staging username/password was rejected by the web server at ' . $host . '. '
                             . 'Check that the HTTP Auth Username and Password in plugin settings match your .htpasswd or nginx auth_basic credentials. '
                             . 'This is the web server blocking the request before it reaches the DataFlair API.';
                    }
                    return 'HTTP Basic Auth required (401). The server at ' . $host . ' requires HTTP Basic Authentication (e.g. .htpasswd). '
                         . 'This is common on staging environments. Go to DataFlair plugin settings and fill in the "HTTP Auth Username" and "HTTP Auth Password" fields. '
                         . 'These are your web server credentials — not your DataFlair API token.';
                }

                if (stripos($wwwAuth, 'Bearer') !== false || ! empty($apiMessage)) {
                    return 'API authentication failed (401). The DataFlair API rejected your Bearer token. '
                         . 'API says: "' . ($apiMessage ?: 'Unauthenticated') . '". '
                         . 'Possible causes: (1) Token is expired or revoked — generate a new one in DataFlair > Configuration > API Credentials. '
                         . '(2) Token is an API Key (dfk_) instead of a Plugin Token (dfp_) — only dfp_ tokens work for this plugin. '
                         . '(3) Token was copy-pasted with extra spaces or line breaks — re-copy it carefully. '
                         . 'Token starts with: ' . substr(trim((string) get_option('dataflair_api_token', '')), 0, 10) . '...';
                }

                if ($isHtmlReply) {
                    return 'Authentication failed (401) — the server at ' . $host . ' returned an HTML page instead of a JSON API response. '
                         . 'This usually means the web server itself (nginx/Apache) is blocking the request before it reaches the DataFlair API. '
                         . 'Most likely cause: HTTP Basic Auth (.htpasswd) is enabled on staging. '
                         . 'Go to plugin settings and fill in the "HTTP Auth Username" and "HTTP Auth Password" fields.';
                }

                return 'Authentication failed (401) at ' . $host . '. '
                     . 'Could not determine the specific cause. Response body: ' . substr($body, 0, 300) . '. '
                     . 'Check: (1) Is staging behind HTTP Basic Auth? Add credentials in plugin settings. '
                     . '(2) Is your dfp_ token valid and not expired? (3) Is the API Base URL correct?';

            case 403:
                return 'Access forbidden (403). The server accepted your credentials but your token does not have permission to access this resource. '
                     . 'API says: "' . ($apiMessage ?: 'Forbidden') . '". '
                     . 'Check that your API credential in DataFlair has the correct permissions and is marked as active.';

            case 404:
                return 'Endpoint not found (404) at ' . $url . '. '
                     . 'This usually means the API Base URL is wrong or the route does not exist. '
                     . 'Expected format: https://tenant.dataflair.ai/api/v1. '
                     . 'Currently configured: ' . get_option('dataflair_api_base_url', '(not set)');

            case 419:
                return 'CSRF token mismatch (419). The API returned a Laravel session error. '
                     . 'This should not happen for API routes. Check that the API Base URL points to /api/v1 routes, not web routes.';

            case 429:
                return 'Rate limited (429). Too many requests to the DataFlair API. '
                     . 'API says: "' . ($apiMessage ?: 'Too Many Requests') . '". '
                     . 'Wait a few minutes and try again, or check your API credential rate limit settings.';

            case 500:
                return 'Server error (500). The DataFlair API encountered an internal error. '
                     . 'This is a server-side issue, not a plugin configuration problem. '
                     . 'API says: "' . ($apiMessage ?: substr($body, 0, 200)) . '". '
                     . 'Contact DataFlair support if this persists.';

            case 502:
            case 503:
            case 504:
                return 'Server unavailable (' . $statusCode . '). The DataFlair API at ' . $host . ' is temporarily unavailable. '
                     . 'This could be a deployment in progress, server overload, or infrastructure issue. Try again in a few minutes.';

            default:
                return 'Unexpected HTTP ' . $statusCode . ' from ' . $host . '. '
                     . ($apiMessage ? 'API says: "' . $apiMessage . '". ' : '')
                     . 'Response body: ' . substr($body, 0, 300);
        }
    }

    /**
     * Pull a single header value out of either an array bag or a
     * Requests-shaped CaseInsensitiveDictionary, returning '' on miss.
     *
     * @param array|object $headers
     */
    private function headerValue($headers, string $name): string
    {
        if (is_object($headers) && isset($headers[$name])) {
            return (string) $headers[$name];
        }
        if (is_array($headers) && isset($headers[$name])) {
            return (string) $headers[$name];
        }
        return '';
    }
}
