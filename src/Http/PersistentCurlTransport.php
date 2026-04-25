<?php
/**
 * Persistent cURL transport — reuses one CurlHandle across HTTP calls so the
 * TLS connection to repeated upstream hosts (sigma.dataflair.ai, brand logo
 * CDNs) stays warm.
 *
 * Phase 9.6 follow-up. Trades the WordPress HTTP filter ecosystem for raw
 * curl in exchange for ~150-300ms saved per repeated request to the same
 * host. Used by ApiClient (single API hit per AJAX request) and
 * LogoDownloader (HEAD + GET fanout per brand). The handle is a static
 * class property so it survives across requests within a single PHP-FPM
 * worker - consecutive AJAX calls routed to the same worker pay TLS
 * handshake cost only on the first hit.
 *
 * Hard requirements preserved from the wp_remote_get-based path:
 *   - Response size cap via CURLOPT_WRITEFUNCTION abort
 *   - Per-call timeout
 *   - Basic Auth (URL credentials path) supported by the caller
 *   - Header injection
 *
 * Falls back to a "not available" signal when the curl extension is missing
 * so callers can revert to wp_remote_get cleanly.
 *
 * @package DataFlair\Toplists\Http
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Http;

final class PersistentCurlTransport
{
    private static ?\CurlHandle $handle = null;

    public static function isAvailable(): bool
    {
        return function_exists('curl_init')
            && function_exists('curl_reset')
            && class_exists(\CurlHandle::class);
    }

    public static function get(
        string $url,
        array $headers,
        int $timeoutSeconds,
        int $maxBytes,
        bool $sslVerify = true
    ): array {
        if (!self::isAvailable()) {
            return ['ok' => false, 'code' => 0, 'body' => '', 'error' => 'curl_unavailable', 'truncated' => false, 'bytes' => 0];
        }

        $ch = self::handle();

        $buffer    = '';
        $truncated = false;

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_HTTPHEADER     => self::flattenHeaders($headers),
            CURLOPT_TIMEOUT        => max(1, $timeoutSeconds),
            CURLOPT_CONNECTTIMEOUT => max(2, min($timeoutSeconds, 10)),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_NOBODY         => false,
            CURLOPT_HTTPGET        => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_SSL_VERIFYPEER => $sslVerify,
            CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_USERAGENT      => 'DataFlair-Toplists/persistent-curl',
            CURLOPT_WRITEFUNCTION  => static function ($_ch, string $chunk) use (&$buffer, &$truncated, $maxBytes): int {
                $len = strlen($chunk);
                if (strlen($buffer) + $len > $maxBytes) {
                    $truncated = true;
                    return -1;
                }
                $buffer .= $chunk;
                return $len;
            },
        ]);

        $exec_ok = curl_exec($ch) !== false;
        $code    = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err     = curl_errno($ch) ? curl_error($ch) : null;

        if ($truncated) {
            return [
                'ok' => false, 'code' => $code, 'body' => '',
                'error' => 'response_too_large', 'truncated' => true,
                'bytes' => strlen($buffer),
            ];
        }

        if (!$exec_ok && $err !== null) {
            return [
                'ok' => false, 'code' => $code, 'body' => '',
                'error' => 'curl_error: ' . $err, 'truncated' => false,
                'bytes' => 0,
            ];
        }

        return [
            'ok' => true, 'code' => $code, 'body' => $buffer,
            'error' => null, 'truncated' => false, 'bytes' => strlen($buffer),
        ];
    }

    public static function head(string $url, int $timeoutSeconds, bool $sslVerify = true): array
    {
        if (!self::isAvailable()) {
            return ['ok' => false, 'code' => 0, 'content_length' => 0, 'error' => 'curl_unavailable'];
        }

        $ch = self::handle();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_HTTPHEADER     => [],
            CURLOPT_TIMEOUT        => max(1, $timeoutSeconds),
            CURLOPT_CONNECTTIMEOUT => max(2, min($timeoutSeconds, 10)),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_NOBODY         => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_SSL_VERIFYPEER => $sslVerify,
            CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
            CURLOPT_USERAGENT      => 'DataFlair-Toplists/persistent-curl',
        ]);

        $exec_ok = curl_exec($ch) !== false;
        $code    = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $len     = (int) curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        $err     = curl_errno($ch) ? curl_error($ch) : null;

        if (!$exec_ok && $err !== null) {
            return ['ok' => false, 'code' => $code, 'content_length' => 0, 'error' => 'curl_error: ' . $err];
        }

        return ['ok' => true, 'code' => $code, 'content_length' => max(0, $len), 'error' => null];
    }

    private static function handle(): \CurlHandle
    {
        if (self::$handle === null) {
            self::$handle = curl_init();
        } else {
            curl_reset(self::$handle);
        }
        return self::$handle;
    }

    private static function flattenHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            if (is_int($k)) {
                $out[] = (string) $v;
            } else {
                $out[] = $k . ': ' . $v;
            }
        }
        return $out;
    }
}
