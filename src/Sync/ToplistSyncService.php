<?php
/**
 * Toplist sync pipeline. Extracted from DataFlair_Toplists::ajax_sync_toplists_batch
 * and DataFlair_Toplists::sync_toplists_page_per_id (Phase 3).
 *
 * This class orchestrates the per-page sync + per-ID fallback path. It does
 * not handle nonce/capability checks — those stay at the AJAX-handler gate.
 * It emits dataflair_sync_batch_started + dataflair_sync_batch_finished +
 * dataflair_sync_item_failed at the same call sites the god-class did.
 *
 * Every Phase 0B / Phase 1 invariant is preserved byte-for-byte:
 *   - 15 MB / 12 s HTTP cap via the injected HttpClientInterface.
 *   - 25 s WallClockBudget with 3 s headroom checked between items.
 *   - Progressive-split fallback (per_page=5 then per_page=1) on bulk 5xx.
 *   - Paginated DELETE when page === 1, no TRUNCATE.
 *   - Option rename fallback (dataflair_last_toplists_sync +
 *     dataflair_last_toplists_cron_run written in parallel for one release).
 *   - H4 memory cleanup via unset() + gc_collect_cycles().
 *
 * @package DataFlair\Toplists\Sync
 * @since   1.12.1 (Phase 3)
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Sync;

use DataFlair\Toplists\Http\HttpClientInterface;
use DataFlair\Toplists\Logging\LoggerInterface;
use DataFlair\Toplists\Support\WallClockBudget;

final class ToplistSyncService implements ToplistSyncServiceInterface
{
    /** @var callable|null */
    private $errorBuilder;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly ToplistPersisterInterface $persister,
        private readonly LoggerInterface $logger,
        private readonly string $token,
        private readonly string $baseUrl,
        ?callable $errorBuilder = null
    ) {
        $this->errorBuilder = $errorBuilder;
    }

    public function syncPage(SyncRequest $request): SyncResult
    {
        $page    = $request->page;
        $perPage = $request->perPage;

        $batchT0 = microtime(true);
        $this->logger->info('ToplistSync.page_start page=' . $page . ' per_page=' . $perPage . ' budget_s=' . $request->budgetSeconds);
        do_action('dataflair_sync_batch_started', [
            'type'           => 'toplists',
            'page'           => $page,
            'per_page'       => $perPage,
            'budget_seconds' => $request->budgetSeconds,
        ]);

        $budget = new WallClockBudget($request->budgetSeconds);

        // Sigma's /toplists already returns full items+brand+offer+tracker
        // payload by default. `?include=items` is a no-op (verified
        // 2026-04-25: identical bytes, identical time, with vs without).
        $listUrl = $this->baseUrl . '/toplists?per_page=' . $perPage
            . '&page=' . $page;

        $this->logger->debug('ToplistSync.http_request url=' . $listUrl);
        $httpT0   = microtime(true);
        $response = $this->http->get($listUrl, $this->token, 20, 2, $budget);
        $httpMs   = (int) round((microtime(true) - $httpT0) * 1000);
        $bytes    = is_wp_error($response) ? 0 : strlen((string) wp_remote_retrieve_body($response));
        $status   = is_wp_error($response) ? $response->get_error_code() : (int) wp_remote_retrieve_response_code($response);
        $this->logger->info(
            'ToplistSync.http page=' . $page . ' per_page=' . $perPage
            . ' elapsed_ms=' . $httpMs . ' bytes=' . $bytes . ' status=' . $status
        );

        if (is_wp_error($response)) {
            $this->logger->warning(
                'ToplistSync: bulk call WP_Error on page ' . $page
                . ' (' . $response->get_error_message() . ') — falling back to per-ID fetches'
            );
            $fallback = $this->syncPagePerId($page, $budget);
            if ($fallback->success) {
                $this->emitBatchFinished($page, $fallback->lastPage, $fallback->synced, $fallback->errors, $fallback->partial, $fallback->isComplete, $batchT0);
                return $fallback;
            }
            return SyncResult::failure(
                $page,
                'Failed to fetch toplist page ' . $page . ': ' . $response->get_error_message()
            );
        }

        $statusCode      = wp_remote_retrieve_response_code($response);
        $body            = wp_remote_retrieve_body($response);
        $responseHeaders = wp_remote_retrieve_headers($response);

        // Auto-detect and store base URL from first successful response.
        if ($page === 1 && $statusCode === 200) {
            $parsed = parse_url($listUrl);
            if (isset($parsed['scheme'], $parsed['host'])) {
                $detected = $parsed['scheme'] . '://' . $parsed['host'] . '/api/v1';
                $current  = get_option('dataflair_api_base_url');
                if (empty($current) || $current !== $detected) {
                    update_option('dataflair_api_base_url', $detected);
                }
            }
        }

        if ($statusCode !== 200) {
            // Contract-mismatch rejections apply to every endpoint equally, so
            // per-ID fallback would only repeat the same 409 N times. Record
            // the state for the admin notice and stop before any local write.
            $mismatch = ContractMismatch::fromResponse((int) $statusCode, (string) $body);
            if ($mismatch !== null) {
                ContractMismatch::record($mismatch, $listUrl, 'toplists');
                $this->logger->warning(
                    'ToplistSync: contract mismatch on page ' . $page . ' — sync aborted before any local write'
                );
                $failureMessage = ContractMismatch::describe($mismatch);
                do_action('dataflair_sync_item_failed', [
                    'type'  => 'toplists',
                    'page'  => $page,
                    'error' => $failureMessage,
                ]);
                return SyncResult::failure($page, $failureMessage);
            }

            if (in_array($statusCode, [500, 502, 503, 504], true)) {
                $this->logger->warning(
                    'ToplistSync: bulk HTTP ' . $statusCode
                    . ' on page ' . $page . ' — falling back to per-ID fetches'
                );
                $fallback = $this->syncPagePerId($page, $budget);
                if ($fallback->success) {
                    $this->emitBatchFinished(
                        $page,
                        $fallback->lastPage,
                        $fallback->synced,
                        $fallback->errors,
                        $fallback->partial,
                        $fallback->isComplete,
                        $batchT0
                    );
                    return $fallback;
                }
            }
            $errorMsg = $this->buildDetailedApiError(
                $statusCode,
                $body,
                $responseHeaders,
                $listUrl
            );
            return SyncResult::failure($page, $errorMsg);
        }

        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return SyncResult::failure($page, 'JSON decode error: ' . json_last_error_msg());
        }
        if (!isset($data['data']) || !is_array($data['data'])) {
            // A retyped `data` (string/object) must fail here, not launder
            // through the canary as an empty payload and reach the wipe.
            return SyncResult::failure(
                $page,
                'Invalid response format from API. Expected "data" key with an array in response.'
            );
        }

        // Contract canary: deep-validate EVERY page before persisting it. A
        // field rename inside data[].items passes the shallow gates above but
        // would blank cards site-wide once stored; the canary turns that
        // silent drift into a loud, data-preserving abort.
        //
        // Every page, not just page 1: a rename is a systematic backend change
        // so page 1 catches it in practice, but a page-1 sample below the
        // canary's threshold (few toplists, no items) would otherwise wave the
        // remaining pages through unchecked. Filter escape hatch for
        // emergencies only.
        if ((bool) apply_filters('dataflair_contract_canary', true)) {
            $canaryFailure = (new ContractCanary())->assess($data['data']);
            if ($canaryFailure !== null) {
                ContractMismatch::record(
                    ['message' => $canaryFailure, 'min_plugin_version' => ''],
                    $listUrl,
                    'toplists'
                );
                $this->logger->warning('ToplistSync: contract canary failed on page ' . $page . ' — ' . $canaryFailure);
                $failureMessage = 'DataFlair contract canary: ' . $canaryFailure . ' '
                    . ($page === 1
                        ? 'Sync aborted before any local write; your site continues to show the last synced data.'
                        : 'Sync stopped at page ' . $page . '; pages already synced are kept, the rest are left unchanged.')
                    . ' ' . ContractMismatch::whatToDo('');
                do_action('dataflair_sync_item_failed', [
                    'type'  => 'toplists',
                    'page'  => $page,
                    'error' => $failureMessage,
                ]);
                return SyncResult::failure($page, $failureMessage);
            }
        }

        // Safety stop: an empty page-1 payload against a populated local
        // table is far more likely a backend scoping/auth regression than a
        // deliberate delete-everything. Preserve local data and fail loudly;
        // the filter is the deliberate override for a real wipe.
        if ($page === 1 && $data['data'] === [] && !(bool) apply_filters('dataflair_allow_empty_sync', false)) {
            global $wpdb;
            $existingRows = (int) $wpdb->get_var(
                'SELECT COUNT(*) FROM ' . $wpdb->prefix . DATAFLAIR_TABLE_NAME
            );
            if ($existingRows > 0) {
                // Recorded without the "safety stop" prefix: the admin notice
                // already opens with "DataFlair sync is paused:".
                // Two audiences in one sentence: the admin gets a real next
                // step (report it), the developer gets the filter name. Never
                // tell a site admin to "enable a filter" as if it were a
                // setting they can click.
                $recorded = 'the API returned zero toplists while this site has ' . $existingRows
                    . ' stored, so the local data was preserved. This is almost always a change or fault on the DataFlair side, '
                    . 'such as an API credential losing access to its site. Send this message to DataFlair support. '
                    . 'If every toplist really was removed on purpose, a developer can allow the wipe with the dataflair_allow_empty_sync filter.';
                $failureMessage = 'DataFlair sync safety stop: ' . $recorded;
                ContractMismatch::record(
                    ['message' => ucfirst($recorded), 'min_plugin_version' => ''],
                    $listUrl,
                    'toplists'
                );
                $this->logger->warning('ToplistSync: empty page-1 payload with ' . $existingRows . ' local rows — wipe refused');
                do_action('dataflair_sync_item_failed', [
                    'type'  => 'toplists',
                    'page'  => $page,
                    'error' => $failureMessage,
                ]);
                return SyncResult::failure($page, $failureMessage);
            }
        }

        // Destructive page-1 reset runs only AFTER the page-1 response has
        // been fetched and validated. Wiping before the fetch (the pre-2.3.0
        // order) left the site with an empty toplists table whenever the
        // backend errored, turning a backend outage into a front-end outage.
        // When a slow fetch already burned the budget, skip the wipe but keep
        // persisting (rows upsert): a partial retry here would loop in
        // drivers with no partial cap, and the same slow fetch would trip
        // the guard deterministically on every retry. Stale upstream-deleted
        // rows simply persist until the next healthy run wipes them.
        if ($page === 1) {
            if ($budget->exceeded(8.0)) {
                $this->logger->warning('ToplistSync: budget too low for the page-1 wipe — upserting without the stale-row wipe');
            } else {
                $this->logger->info('ToplistSync.reset_state page=1 — clearing transients + truncating toplists table');
                $this->resetSyncState();
            }
        }

        $synced    = 0;
        $errors    = 0;
        $lastPage  = 1;
        $endpoints = [];

        if (isset($data['meta']['last_page'])) {
            $lastPage = (int) $data['meta']['last_page'];
            set_transient('dataflair_toplists_batch_last_page', $lastPage, HOUR_IN_SECONDS);
        }

        $budgetExhausted = false;

        if (is_array($data['data'])) {
            foreach ($data['data'] as $toplist) {
                if ($budget->exceeded(3.0)) {
                    $budgetExhausted = true;
                    break;
                }

                if (isset($toplist['id'])) {
                    $endpoint    = $this->baseUrl . '/toplists/' . $toplist['id'];
                    $endpoints[] = $endpoint;

                    $toplistJson = wp_json_encode(['data' => $toplist]);
                    $jsonBytes   = strlen((string) $toplistJson);
                    $itemT0      = microtime(true);
                    $result      = $this->persister->store($toplist, (string) $toplistJson);
                    $itemMs      = (int) round((microtime(true) - $itemT0) * 1000);
                    $this->logger->debug(
                        'ToplistSync.store id=' . (int) $toplist['id']
                        . ' name="' . (string) ($toplist['name'] ?? '') . '"'
                        . ' bytes=' . $jsonBytes
                        . ' elapsed_ms=' . $itemMs
                        . ' result=' . ($result ? 'ok' : 'fail')
                    );

                    if ($result) {
                        $synced++;
                    } else {
                        $errors++;
                    }
                    unset($toplistJson, $result, $endpoint);
                }
                unset($toplist);
            }
        }

        if (!empty($endpoints)) {
            // Page 1 always rebuilds the cache from scratch: when the wipe
            // was skipped (low budget) the reset did not blank the option,
            // and appending would grow the autoloaded list without bound.
            $existing = $page === 1 ? '' : get_option('dataflair_api_endpoints', '');
            $joined   = implode("\n", $endpoints);
            if (!empty($existing)) {
                $joined = $existing . "\n" . $joined;
            }
            update_option('dataflair_api_endpoints', $joined);
        }

        unset($data, $body);
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }

        $isComplete = !$budgetExhausted && $page >= $lastPage;
        if ($isComplete) {
            $this->markSyncCompleted();
            if ($errors === 0 && $synced > 0) {
                // Only a validated bulk sync that actually stored rows,
                // error-free, proves the toplists contract works again.
                // Fallback, skip, and zero-row paths never clear.
                ContractMismatch::clear('toplists');
            }
        }

        $this->logger->info(
            'ToplistSync.page_done page=' . $page . '/' . $lastPage
            . ' synced=' . $synced . ' errors=' . $errors
            . ' partial=' . ($budgetExhausted ? '1' : '0')
            . ' elapsed_s=' . round(microtime(true) - $batchT0, 2)
            . ' mem_peak_mb=' . round(memory_get_peak_usage(true) / 1048576, 1)
        );

        $this->emitBatchFinished($page, $lastPage, $synced, $errors, $budgetExhausted, $isComplete, $batchT0);

        return SyncResult::success(
            $page,
            $lastPage,
            $synced,
            $errors,
            $budgetExhausted,
            $isComplete
        );
    }

    public function syncByApiToplistIds(array $apiToplistIds, int $budgetSeconds = 60): SyncResult
    {
        $apiToplistIds = array_values(array_filter(array_map('intval', $apiToplistIds)));
        if (empty($apiToplistIds)) {
            return SyncResult::success(1, 1, 0, 0, false, true);
        }

        $budget  = new WallClockBudget((float) $budgetSeconds);
        $synced  = 0;
        $errors  = 0;
        $partial = false;

        foreach ($apiToplistIds as $id) {
            if ($budget->exceeded(3.0)) {
                $partial = true;
                break;
            }
            $endpoint = $this->baseUrl . '/toplists/' . $id;
            if ($this->persister->fetchAndStore($endpoint, $this->token)) {
                $synced++;
            } else {
                $errors++;
            }
        }

        return SyncResult::success(1, 1, $synced, $errors, $partial, !$partial);
    }

    /**
     * Fallback — per-ID fetches when the bulk `include=items` call fails.
     * Progressive split: per_page=5 × 2 then per_page=1 on sub-slices that failed.
     */
    private function syncPagePerId(int $page, WallClockBudget $budget): SyncResult
    {
        $deadline        = time() + 30;
        $collectedIds    = [];
        $attemptLog      = [];
        $naturalLastPage = 0;

        $trySlice = function (int $perPage, int $subPage) use (
            &$attemptLog,
            &$naturalLastPage,
            $deadline
        ) {
            if (time() >= $deadline) {
                $attemptLog[] = ['per_page' => $perPage, 'page' => $subPage, 'status' => 'deadline_exceeded'];
                return null;
            }

            $url      = $this->baseUrl . '/toplists?per_page=' . $perPage . '&page=' . $subPage;
            $response = $this->http->get($url, $this->token, 15, 0);

            $entry = ['per_page' => $perPage, 'page' => $subPage];

            if (is_wp_error($response)) {
                $entry['status'] = 'wp_error';
                $entry['error']  = $response->get_error_message();
                $attemptLog[]    = $entry;
                return null;
            }

            $status          = wp_remote_retrieve_response_code($response);
            $entry['status'] = $status;
            if ($status !== 200) {
                $entry['body']  = substr(wp_remote_retrieve_body($response), 0, 200);
                $attemptLog[]   = $entry;
                return null;
            }

            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (json_last_error() !== JSON_ERROR_NONE || !isset($data['data']) || !is_array($data['data'])) {
                $entry['status'] = 'invalid_json';
                $attemptLog[]    = $entry;
                return null;
            }

            if (isset($data['meta']['last_page'])) {
                $subLast = (int) $data['meta']['last_page'];
                $natural = (int) ceil(($subLast * $perPage) / 10);
                if ($natural > $naturalLastPage) {
                    $naturalLastPage = $natural;
                }
            }

            $ids = [];
            foreach ($data['data'] as $toplist) {
                if (isset($toplist['id'])) {
                    $ids[] = $toplist['id'];
                }
            }
            $entry['ids']  = count($ids);
            $attemptLog[]  = $entry;
            return $ids;
        };

        // Level 1: per_page=5 × 2 sub-pages
        $failedSub5 = [];
        for ($i = 0; $i < 2; $i++) {
            $sub5 = (2 * $page) - 1 + $i;
            $ids5 = $trySlice(5, $sub5);
            if (is_array($ids5)) {
                $collectedIds = array_values(array_unique(array_merge($collectedIds, $ids5)));
            } else {
                $failedSub5[] = $sub5;
            }
        }

        // Level 2: per_page=1 × 5 on failed sub-pages
        foreach ($failedSub5 as $sub5) {
            $start1 = (($sub5 - 1) * 5) + 1;
            for ($j = 0; $j < 5; $j++) {
                if (time() >= $deadline) {
                    break 2;
                }
                $ids1 = $trySlice(1, $start1 + $j);
                if (is_array($ids1)) {
                    $collectedIds = array_values(array_unique(array_merge($collectedIds, $ids1)));
                }
            }
        }

        if ($naturalLastPage <= 0) {
            $stored = get_transient('dataflair_toplists_batch_last_page');
            if ($stored) {
                $naturalLastPage = (int) $stored;
            }
        }
        if ($naturalLastPage <= 0) {
            $naturalLastPage = $page + 1;
        }

        if (empty($collectedIds)) {
            $this->logger->error(
                'ToplistSync: page ' . $page
                . ' unrecoverable; every split failed. Attempts: '
                . wp_json_encode($attemptLog)
            );
            // Zero rows fetched: never stamp completion here — a fresh
            // "last sync" timestamp would suppress staleness monitoring for
            // a run that synced nothing.
            $isComplete = $page >= $naturalLastPage;
            return SyncResult::success(
                $page,
                $naturalLastPage,
                0,
                0,
                false,
                $isComplete,
                [
                    'skipped'     => true,
                    'skip_reason' => 'API returned errors for every split of page ' . $page
                        . ' (tried per_page 5 and per_page 1 slices). This page will be retried on the next full sync.',
                    'fallback'    => true,
                    'next_page'   => $page + 1,
                ]
            );
        }

        // Budget may already be spent by the bulk HTTP retry attempts.
        // Advance instead of re-queuing the same page forever. Zero rows
        // fetched, so completion is never stamped from this path either.
        if ($budget->exceeded(3.0)) {
            $isComplete = $page >= $naturalLastPage;
            return SyncResult::success(
                $page, $naturalLastPage, 0, 0, true, $isComplete,
                ['fallback' => true, 'budget_skip' => true, 'next_page' => $page + 1]
            );
        }

        $synced           = 0;
        $errors           = 0;
        $endpoints        = [];
        $budgetExhausted  = false;

        foreach ($collectedIds as $id) {
            if ($budget->exceeded(3.0)) {
                $budgetExhausted = true;
                break;
            }
            $endpoint    = $this->baseUrl . '/toplists/' . $id;
            $endpoints[] = $endpoint;

            if ($this->persister->fetchAndStore($endpoint, $this->token)) {
                $synced++;
            } else {
                $errors++;
            }
            unset($endpoint);
        }

        if (!empty($endpoints)) {
            if ($page === 1) {
                // Page 1 rebuilds the cache the way the bulk path's reset
                // would have; appending here grew the autoloaded option
                // without bound and pinned stale first entries forever.
                if ($synced > 0) {
                    $this->clearTrackerTransients();
                }
                update_option('dataflair_api_endpoints', implode("\n", $endpoints));
            } else {
                $existing = get_option('dataflair_api_endpoints', '');
                $joined   = implode("\n", $endpoints);
                if (!empty($existing)) {
                    $joined = $existing . "\n" . $joined;
                }
                update_option('dataflair_api_endpoints', $joined);
            }
        }
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }

        $isComplete = !$budgetExhausted && $page >= $naturalLastPage;
        if ($isComplete && $synced > 0) {
            // Completion timestamps require rows actually fetched; an
            // all-errors fallback must not look like a healthy sync.
            $this->markSyncCompleted();
        }

        $partial = $budgetExhausted || count($collectedIds) < 20;

        return SyncResult::success(
            $page,
            $naturalLastPage,
            $synced,
            $errors,
            $partial,
            $isComplete,
            [
                'fallback'  => true,
                'next_page' => $budgetExhausted ? $page : ($page + 1),
            ]
        );
    }

    private function resetSyncState(): void
    {
        $this->clearTrackerTransients();
        global $wpdb;
        $tableName = $wpdb->prefix . DATAFLAIR_TABLE_NAME;
        $this->deleteAllPaginated($tableName, 500);

        update_option('dataflair_api_endpoints', '');
        delete_transient('dataflair_toplists_batch_last_page');
    }

    private function clearTrackerTransients(): void
    {
        global $wpdb;
        $chunk = 1000;
        foreach (
            [
                '_transient_dataflair_tracker_%',
                '_transient_timeout_dataflair_tracker_%',
            ] as $pattern
        ) {
            $sql = $wpdb->prepare(
                "DELETE FROM {$wpdb->options}
                 WHERE option_name LIKE %s
                 ORDER BY option_id
                 LIMIT %d",
                $pattern,
                $chunk
            );
            while (($deleted = $wpdb->query($sql)) > 0) {
                // loop
            }
        }
    }

    private function deleteAllPaginated(string $table, int $batch): void
    {
        global $wpdb;
        do {
            $rows = (int) $wpdb->query("DELETE FROM {$table} LIMIT {$batch}");
        } while ($rows > 0);
    }

    private function markSyncCompleted(): void
    {
        $ts = time();
        update_option('dataflair_last_toplists_sync', $ts);
        update_option('dataflair_last_toplists_cron_run', $ts);
    }

    private function emitBatchFinished(
        int $page, int $lastPage, int $synced, int $errors, bool $partial, bool $isComplete, float $t0
    ): void {
        do_action('dataflair_sync_batch_finished', [
            'type'            => 'toplists',
            'page'            => $page,
            'last_page'       => $lastPage,
            'items_done'      => $synced,
            'errors'          => $errors,
            'partial'         => $partial,
            'is_complete'     => $isComplete,
            'elapsed_seconds' => round(microtime(true) - $t0, 3),
            'memory_peak_mb'  => round(memory_get_peak_usage(true) / 1024 / 1024, 1),
        ]);
    }

    private function buildDetailedApiError($statusCode, $body, $headers, string $url): string
    {
        if (is_callable($this->errorBuilder)) {
            return (string) ($this->errorBuilder)($statusCode, $body, $headers, $url);
        }
        $snippet = is_string($body) ? substr($body, 0, 400) : '';
        return 'API error (' . (int) $statusCode . ') for ' . $url
            . (empty($snippet) ? '' : ': ' . $snippet);
    }
}
