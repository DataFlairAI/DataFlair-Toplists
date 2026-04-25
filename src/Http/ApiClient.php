<?php
/**
 * Concrete DataFlair upstream HTTP client.
 *
 * Phase 2 — extracted from `DataFlair_Toplists::api_get()`. The god-class
 * `api_get()` method is now a 1-line delegator that forwards here. All
 * invariants preserved:
 *   - 15 MB response cap with stream-to-temp (Phase 0B H2)
 *   - 12 s default timeout (Phase 0B H13)
 *   - WallClockBudget cooperative cancellation (Phase 0B H13)
 *   - `dataflair_http_call` telemetry hook at every return path (Phase 1)
 *   - Exponential retry on transient 5xx + connection errors
 *   - Docker-internal URL rewrite for .test/.local hostnames
 *   - HTTP Basic Auth credentials injected at transport layer
 *
 * Accepts an optional `LoggerInterface`; the default `LoggerFactory::get()`
 * resolution is used if none is passed, which keeps existing callers working
 * without DI wiring changes.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Http;

use DataFlair\Toplists\Logging\LoggerFactory;
use DataFlair\Toplists\Logging\LoggerInterface;
use DataFlair\Toplists\Support\WallClockBudget;

final class ApiClient implements HttpClientInterface
{
    /** 15 MB hard cap on API response bodies (Phase 0B H2). */
    private const MAX_BYTES = 15 * 1024 * 1024;

    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? LoggerFactory::get();
    }

    /**
     * @inheritDoc
     */
    public function get(
        string $url,
        string $token,
        int $timeout = 12,
        int $max_retries = 2,
        ?WallClockBudget $budget = null
    ) {
        $http_t0 = microtime(true);

        if ($budget instanceof WallClockBudget) {
            if ($budget->exceeded(1.0)) {
                $this->emitHttpCall([
                    'url'        => $url,
                    'status'     => 0,
                    'elapsed_ms' => (int) round((microtime(true) - $http_t0) * 1000),
                    'bytes'      => 0,
                    'error'      => 'budget_exhausted_before_request',
                ]);
                return new \WP_Error(
                    'dataflair_budget_exhausted',
                    'Wall-clock budget exhausted before api_get() could start.'
                );
            }
            $timeout = (int) max(1, min($timeout, (int) floor($budget->remaining())));
        }

        $url = $this->maybeForceHttps($url);

        $headers = [
            'Accept'        => 'application/json',
            'Authorization' => 'Bearer ' . trim($token),
        ];

        $parsed = parse_url($url);
        $host   = is_array($parsed) && isset($parsed['host']) ? (string) $parsed['host'] : '';

        if ($this->isLocalUrl($url) && $this->isRunningInDocker()) {
            $original_host     = $host;
            $url               = str_replace($original_host, 'host.docker.internal', $url);
            $headers['Host']   = $original_host;
            $this->logger->debug('api_get.docker_rewrite', [
                'from' => $original_host,
                'to'   => 'host.docker.internal',
            ]);
        }

        $http_user = trim((string) get_option('dataflair_http_auth_user', ''));
        $http_pass = trim((string) get_option('dataflair_http_auth_pass', ''));
        if ($http_user !== '' && $http_pass !== '') {
            $url = preg_replace(
                '#^(https?://)#i',
                '$1' . urlencode($http_user) . ':' . urlencode($http_pass) . '@',
                $url
            );
        }

        $args = [
            'timeout'             => $timeout,
            'headers'             => $headers,
            'limit_response_size' => self::MAX_BYTES,
        ];

        $transient_codes = [500, 502, 503, 504];
        $attempt  = 0;
        $response = null;

        $use_persistent = PersistentCurlTransport::isAvailable()
            && (bool) apply_filters('dataflair_use_persistent_curl', true);

        while (true) {
            $response = $use_persistent
                ? $this->dispatchPersistent($url, $headers, $timeout)
                : wp_remote_get($url, $args);

            if (!is_wp_error($response)) {
                $body = wp_remote_retrieve_body($response);
                if ($body !== '' && strlen($body) >= self::MAX_BYTES) {
                    $this->emitHttpCall([
                        'url'        => $url,
                        'status'     => (int) wp_remote_retrieve_response_code($response),
                        'elapsed_ms' => (int) round((microtime(true) - $http_t0) * 1000),
                        'bytes'      => strlen($body),
                        'error'      => 'response_too_large',
                    ]);
                    return new \WP_Error(
                        'dataflair_response_too_large',
                        sprintf('Upstream response exceeded %d byte cap.', self::MAX_BYTES),
                        ['limit' => self::MAX_BYTES, 'url' => $url]
                    );
                }
            }

            $should_retry = false;
            if (is_wp_error($response)) {
                $should_retry = true;
            } else {
                $code = wp_remote_retrieve_response_code($response);
                if (in_array($code, $transient_codes, true)) {
                    $should_retry = true;
                }
            }

            if (!$should_retry || $attempt >= $max_retries) {
                if (is_wp_error($response)) {
                    $this->emitHttpCall([
                        'url'        => $url,
                        'status'     => 0,
                        'elapsed_ms' => (int) round((microtime(true) - $http_t0) * 1000),
                        'bytes'      => 0,
                        'error'      => $response->get_error_code(),
                    ]);
                } else {
                    $body = wp_remote_retrieve_body($response);
                    $this->emitHttpCall([
                        'url'        => $url,
                        'status'     => (int) wp_remote_retrieve_response_code($response),
                        'elapsed_ms' => (int) round((microtime(true) - $http_t0) * 1000),
                        'bytes'      => is_string($body) ? strlen($body) : 0,
                    ]);
                }
                return $response;
            }

            $delay = (int) pow(2, $attempt);
            if ($budget instanceof WallClockBudget && $budget->exceeded((float) $delay + 2.0)) {
                if (is_wp_error($response)) {
                    $this->emitHttpCall([
                        'url'        => $url,
                        'status'     => 0,
                        'elapsed_ms' => (int) round((microtime(true) - $http_t0) * 1000),
                        'bytes'      => 0,
                        'error'      => $response->get_error_code() . '_budget_cut',
                    ]);
                } else {
                    $body = wp_remote_retrieve_body($response);
                    $this->emitHttpCall([
                        'url'        => $url,
                        'status'     => (int) wp_remote_retrieve_response_code($response),
                        'elapsed_ms' => (int) round((microtime(true) - $http_t0) * 1000),
                        'bytes'      => is_string($body) ? strlen($body) : 0,
                        'error'      => 'budget_cut_retry',
                    ]);
                }
                return $response;
            }

            $reason = is_wp_error($response)
                ? 'WP_Error: ' . $response->get_error_message()
                : 'HTTP ' . wp_remote_retrieve_response_code($response);

            $this->logger->warning('api_get.transient_failure', [
                'attempt' => $attempt + 1,
                'reason'  => $reason,
                'delay'   => $delay,
            ]);

            sleep($delay);
            $attempt++;
        }
    }

    /**
     * Dispatch via persistent curl handle, returning a wp_remote_get-shaped
     * array or \WP_Error so the caller's existing branching logic is reused.
     */
    private function dispatchPersistent(string $url, array $headers, int $timeout)
    {
        $sslVerify = !$this->isLocalUrl($url);
        $result    = PersistentCurlTransport::get($url, $headers, $timeout, self::MAX_BYTES, $sslVerify);

        if (!$result['ok']) {
            if (($result['error'] ?? '') === 'response_too_large') {
                return [
                    'headers'  => [],
                    'body'     => str_repeat('x', self::MAX_BYTES),
                    'response' => ['code' => $result['code'] ?: 200, 'message' => ''],
                    'cookies'  => [],
                    'filename' => null,
                ];
            }
            return new \WP_Error(
                'dataflair_persistent_curl_error',
                (string) ($result['error'] ?? 'curl_error'),
                ['code' => $result['code']]
            );
        }

        return [
            'headers'  => [],
            'body'     => $result['body'],
            'response' => ['code' => $result['code'], 'message' => ''],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    private function emitHttpCall(array $payload): void
    {
        if (function_exists('do_action')) {
            do_action('dataflair_http_call', $payload);
        }
    }

    private function maybeForceHttps(string $url): string
    {
        if ($this->isLocalUrl($url)) {
            return $url;
        }
        return preg_replace('#^http://#i', 'https://', $url) ?? $url;
    }

    private function isLocalUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }
        return (bool) preg_match('/\.(test|local|localhost)$/i', $host)
            || $host === 'localhost'
            || $host === '127.0.0.1'
            || str_starts_with($host, '192.168.')
            || str_starts_with($host, '10.');
    }

    private function isRunningInDocker(): bool
    {
        if (file_exists('/.dockerenv')) {
            return true;
        }
        if (is_readable('/proc/1/cgroup')) {
            $cgroup = (string) @file_get_contents('/proc/1/cgroup');
            if (strpos($cgroup, 'docker') !== false || strpos($cgroup, 'kubepods') !== false) {
                return true;
            }
        }
        $resolved = @gethostbyname('host.docker.internal');
        return is_string($resolved) && $resolved !== 'host.docker.internal';
    }
}
