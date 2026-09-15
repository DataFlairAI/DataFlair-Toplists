<?php
/**
 * Contract for DataFlair upstream HTTP calls.
 *
 * Phase 2 — extracted from god-class `DataFlair_Toplists::api_get()`. The
 * contract preserves the legacy return shape (a `wp_remote_get`-shaped array
 * on success, a `WP_Error` on failure) so existing callers delegate byte-
 * for-byte. Retry, backoff, size cap, budget handling, and Docker/Basic-Auth
 * URL rewriting live in the concrete `ApiClient` implementation.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Http;

use DataFlair\Toplists\Support\WallClockBudget;

interface HttpClientInterface
{
    /**
     * GET a DataFlair API URL.
     *
     * @param string               $url
     * @param string               $token        Bearer token. Trimmed before use.
     * @param int                  $timeout      Seconds. Default 12 s (post-Phase 0B H13).
     * @param int                  $max_retries  Transient-failure retries (500/502/503/504 + connection errors).
     * @param WallClockBudget|null $budget       Optional cooperative wall-clock budget.
     *
     * @return array|\WP_Error                   `wp_remote_get`-shaped array on success, `WP_Error` on failure.
     */
    public function get(
        string $url,
        string $token,
        int $timeout = 12,
        int $max_retries = 2,
        ?WallClockBudget $budget = null
    );

    /**
     * POST a small JSON body to a DataFlair API URL. Webhook sync slice's
     * self-registration call - deliberately simpler than get(): no retry
     * loop (idempotent, user-initiated from Settings; a failure just means
     * re-saving), no size cap (registration responses are tiny). Reuses
     * get()'s auth header and local/Docker URL handling.
     *
     * @param array<string,mixed> $body JSON-encoded and sent as the request body.
     *
     * @return array|\WP_Error `wp_remote_post`-shaped array on success, `WP_Error` on failure.
     */
    public function post(string $url, string $token, array $body, int $timeout = 12);
}
