<?php
/**
 * Phase 9.6 (admin UX redesign) — Ping the configured API base URL.
 *
 * Result is cached in transient `dataflair_api_health` for 60 seconds so
 * the Dashboard tile doesn't hammer the API on every page load.
 *
 * Output: { status: 'healthy'|'failing'|'unconfigured', ping_ms: int, error: string }
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Ajax;

use DataFlair\Toplists\Admin\AjaxHandlerInterface;

final class ApiHealthHandler implements AjaxHandlerInterface
{
    private const TRANSIENT = 'dataflair_api_health';
    private const TTL       = 60;

    public function handle(array $request): array
    {
        $force = !empty($request['force']);
        if (!$force) {
            $cached = get_transient(self::TRANSIENT);
            if (is_array($cached)) {
                return ['success' => true, 'data' => $cached];
            }
        }

        $token    = trim((string) get_option('dataflair_api_token', ''));
        $base_url = trim((string) get_option('dataflair_api_base_url', ''));

        if ($token === '' || $base_url === '') {
            $data = ['status' => 'unconfigured', 'ping_ms' => 0, 'error' => 'API token or base URL not configured.'];
            set_transient(self::TRANSIENT, $data, self::TTL);
            return ['success' => true, 'data' => $data];
        }

        $probe_url = rtrim($base_url, '/') . '/toplists?per_page=1';
        $start     = microtime(true);
        $resp      = wp_remote_get($probe_url, [
            'timeout'   => 5,
            'headers'   => ['Authorization' => 'Bearer ' . $token],
            'sslverify' => false,
        ]);
        $ping_ms = (int) round((microtime(true) - $start) * 1000);

        if (is_wp_error($resp)) {
            $data = ['status' => 'failing', 'ping_ms' => $ping_ms, 'error' => $resp->get_error_message()];
            set_transient(self::TRANSIENT, $data, self::TTL);
            return ['success' => true, 'data' => $data];
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        if ($code >= 200 && $code < 300) {
            $status = 'healthy';
            $error  = '';
        } elseif ($code === 401 || $code === 403) {
            $status = 'failing';
            $error  = "HTTP {$code} — check API token";
        } else {
            $status = 'failing';
            $error  = "HTTP {$code}";
        }

        $data = ['status' => $status, 'ping_ms' => $ping_ms, 'error' => $error];
        set_transient(self::TRANSIENT, $data, self::TTL);
        return ['success' => true, 'data' => $data];
    }
}
