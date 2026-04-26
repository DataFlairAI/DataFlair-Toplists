<?php
/**
 * Phase 9.6 (admin UX redesign) — Ping the configured API base URL.
 *
 * Two-layer storage:
 *   - Transient `dataflair_api_health` (60s) throttles re-pings.
 *   - Option   `dataflair_api_health_last` persists the last result indefinitely
 *     so the Dashboard tile keeps showing the last known status with a
 *     "checked X ago" timestamp instead of falling back to "Unknown".
 *
 * Output: { status: 'healthy'|'failing'|'unconfigured', ping_ms: int,
 *           error: string, checked_at: int }
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Ajax;

use DataFlair\Toplists\Admin\AjaxHandlerInterface;

final class ApiHealthHandler implements AjaxHandlerInterface
{
    private const TRANSIENT     = 'dataflair_api_health';
    private const LAST_OPTION   = 'dataflair_api_health_last';
    private const TTL           = 60;

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
            return $this->persist([
                'status'  => 'unconfigured',
                'ping_ms' => 0,
                'error'   => 'API token or base URL not configured.',
            ]);
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
            return $this->persist([
                'status'  => 'failing',
                'ping_ms' => $ping_ms,
                'error'   => $resp->get_error_message(),
            ]);
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

        return $this->persist([
            'status'  => $status,
            'ping_ms' => $ping_ms,
            'error'   => $error,
        ]);
    }

    /**
     * Stamp checked_at, write to both the throttling transient and the
     * persistent last-known option, return the success envelope.
     *
     * @param array{status:string,ping_ms:int,error:string} $data
     */
    private function persist(array $data): array
    {
        $data['checked_at'] = time();
        set_transient(self::TRANSIENT, $data, self::TTL);
        update_option(self::LAST_OPTION, $data, false);
        return ['success' => true, 'data' => $data];
    }
}
