<?php
/**
 * Brand sync pipeline. Extracted from DataFlair_Toplists::sync_brands_page
 * (Phase 3).
 *
 * Every Phase 0B / Phase 1 / Phase 0A invariant is preserved byte-for-byte:
 *   - 15 MB / 12 s HTTP cap via HttpClientInterface.
 *   - 25 s WallClockBudget with 3 s headroom between brands.
 *   - 3 MB / 8 s logo cap via LogoDownloaderInterface.
 *   - dataflair_brand_logo_stored hook fires (via LogoDownloader).
 *   - Paginated DELETE when page === 1, no TRUNCATE.
 *   - H4 memory cleanup via unset() + gc_collect_cycles().
 *   - Brand status != 'Active' skip rule.
 *   - Brand row shape identical to god-class upsert arguments.
 *
 * @package DataFlair\Toplists\Sync
 * @since   1.12.1 (Phase 3)
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Sync;

use DataFlair\Toplists\Database\BrandsRepositoryInterface;
use DataFlair\Toplists\Http\HttpClientInterface;
use DataFlair\Toplists\Http\LogoDownloaderInterface;
use DataFlair\Toplists\Logging\LoggerInterface;
use DataFlair\Toplists\Support\WallClockBudget;

final class BrandSyncService implements BrandSyncServiceInterface
{
    /** @var callable */
    private $brandsUrlBuilder;

    /** @var callable|null */
    private $errorBuilder;

    /**
     * @param callable $brandsUrlBuilder fn(int $page): string — returns the
     *                                   full brands-list URL for a given page.
     * @param callable|null $errorBuilder fn(int $status, string $body,
     *                                   mixed $headers, string $url): string
     *                                   — optional Phase 5 handoff.
     */
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LogoDownloaderInterface $logoDownloader,
        private readonly BrandsRepositoryInterface $brands,
        private readonly LoggerInterface $logger,
        private readonly string $token,
        callable $brandsUrlBuilder,
        ?callable $errorBuilder = null
    ) {
        $this->brandsUrlBuilder = $brandsUrlBuilder;
        $this->errorBuilder     = $errorBuilder;
    }

    public function syncPage(SyncRequest $request): SyncResult
    {
        $page = $request->page;

        $batchT0 = microtime(true);
        $this->logger->info('BrandSync.page_start page=' . $page . ' per_page=' . $request->perPage . ' budget_s=' . $request->budgetSeconds);
        do_action('dataflair_sync_batch_started', [
            'type'           => 'brands',
            'page'           => $page,
            'per_page'       => $request->perPage,
            'budget_seconds' => $request->budgetSeconds,
        ]);

        if ($page === 1) {
            global $wpdb;
            $brandsTable = $wpdb->prefix . 'dataflair_brands';
            $this->deleteAllPaginated($brandsTable, 500);
        }

        $budget = new WallClockBudget($request->budgetSeconds);

        $result = $this->syncBrandsPage($page, $budget, $request->perPage);

        if (!$result['success']) {
            do_action('dataflair_sync_item_failed', [
                'type'  => 'brands',
                'page'  => $page,
                'error' => (string) ($result['message'] ?? ''),
            ]);
            return SyncResult::failure($page, (string) $result['message']);
        }

        $partial    = !empty($result['partial']);
        $lastPage   = (int) $result['last_page'];
        $isComplete = !$partial && $page >= $lastPage;

        do_action('dataflair_sync_batch_finished', [
            'type'            => 'brands',
            'page'            => $page,
            'last_page'       => $lastPage,
            'items_done'      => (int) ($result['synced'] ?? 0),
            'errors'          => (int) ($result['errors'] ?? 0),
            'partial'         => $partial,
            'is_complete'     => $isComplete,
            'elapsed_seconds' => round(microtime(true) - $batchT0, 3),
            'memory_peak_mb'  => round(memory_get_peak_usage(true) / 1024 / 1024, 1),
        ]);

        return SyncResult::success(
            $page,
            (int) $result['last_page'],
            (int) $result['synced'],
            (int) $result['errors'],
            $partial,
            $isComplete,
            [
                'total_synced' => (int) $result['total_synced'],
                'total_brands' => (int) ($result['total_brands'] ?? 0),
            ]
        );
    }

    private function syncBrandsPage(int $page, WallClockBudget $budget, int $perPage): array
    {
        $url = (string) call_user_func($this->brandsUrlBuilder, $page, $perPage);
        $this->logger->debug('BrandSync.http_request url=' . $url);

        $httpT0   = microtime(true);
        $response = $this->http->get($url, $this->token, 20, 2, $budget);
        $httpMs   = (int) round((microtime(true) - $httpT0) * 1000);
        $this->logger->info(
            'BrandSync.http page=' . $page
            . ' elapsed_ms=' . $httpMs
            . ' bytes=' . (is_wp_error($response) ? 0 : strlen((string) wp_remote_retrieve_body($response)))
            . ' status=' . (is_wp_error($response) ? $response->get_error_code() : (int) wp_remote_retrieve_response_code($response))
        );

        if (is_wp_error($response)) {
            $msg = 'Failed to fetch brands page ' . $page . ': ' . $response->get_error_message();
            $this->logger->error('BrandSync: ' . $msg);
            return ['success' => false, 'message' => $msg];
        }

        $statusCode      = wp_remote_retrieve_response_code($response);
        $body            = wp_remote_retrieve_body($response);
        $responseHeaders = wp_remote_retrieve_headers($response);

        if ($statusCode !== 200) {
            $msg = $this->buildDetailedApiError($statusCode, $body, $responseHeaders, $url);
            $this->logger->error('BrandSync: ' . $msg);
            return ['success' => false, 'message' => $msg];
        }

        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $msg = 'JSON decode error: ' . json_last_error_msg();
            $this->logger->error('BrandSync: ' . $msg);
            return ['success' => false, 'message' => $msg];
        }
        if (!isset($data['data'])) {
            $msg = 'Invalid response format from API. Expected "data" key.';
            $this->logger->error('BrandSync: ' . $msg);
            return ['success' => false, 'message' => $msg];
        }

        $lastPage = isset($data['meta']['last_page']) ? (int) $data['meta']['last_page'] : 1;
        $total    = isset($data['meta']['total']) ? (int) $data['meta']['total'] : 0;

        $synced                 = 0;
        $errors                 = 0;
        $budgetExhaustedOnItem  = null;

        $this->logger->debug('BrandSync: page ' . $page . ' has ' . count($data['data']) . ' brands');

        $itemIndex = 0;
        foreach ($data['data'] as $brandData) {
            if ($budget->exceeded(3.0)) {
                $budgetExhaustedOnItem = $itemIndex;
                break;
            }
            $itemIndex++;

            $brandStatus = $brandData['brandStatus'] ?? '';
            if ($brandStatus !== 'Active') {
                continue;
            }

            if (!isset($brandData['id'])) {
                $errors++;
                $this->logger->warning('BrandSync: brand missing ID: ' . json_encode($brandData));
                continue;
            }

            $apiBrandId = (int) $brandData['id'];
            $brandName  = $brandData['name'] ?? 'Unnamed Brand';
            $brandSlug  = $brandData['slug'] ?? sanitize_title($brandName);

            $localLogoPath = $this->logoDownloader->download($brandData, (string) $brandSlug);
            if ($localLogoPath) {
                $brandData['local_logo'] = $localLogoPath;
            }

            $productTypes = isset($brandData['productTypes']) && is_array($brandData['productTypes'])
                ? implode(', ', $brandData['productTypes']) : '';

            $licenses = isset($brandData['licenses']) && is_array($brandData['licenses'])
                ? implode(', ', $brandData['licenses']) : '';

            $classificationTypes = isset($brandData['classificationTypes'])
                && is_array($brandData['classificationTypes'])
                ? implode(', ', $brandData['classificationTypes']) : '';

            $topGeosArr = [];
            if (isset($brandData['topGeos']['countries']) && is_array($brandData['topGeos']['countries'])) {
                $topGeosArr = array_merge($topGeosArr, $brandData['topGeos']['countries']);
            }
            if (isset($brandData['topGeos']['markets']) && is_array($brandData['topGeos']['markets'])) {
                $topGeosArr = array_merge($topGeosArr, $brandData['topGeos']['markets']);
            }
            $topGeos = implode(', ', $topGeosArr);

            $brandOffersCount = isset($brandData['offersCount'])
                ? (int) $brandData['offersCount']
                : (isset($brandData['offers']) && is_array($brandData['offers']) ? count($brandData['offers']) : 0);
            if (empty($topGeosArr) && $brandOffersCount > 0) {
                $this->logger->warning(sprintf(
                    'BrandSync: Brand #%d (%s): has %d offer(s) but no topGeos — check DataFlair admin',
                    $apiBrandId,
                    $brandName,
                    $brandOffersCount
                ));
            }

            $offersCount = isset($brandData['offers']) && is_array($brandData['offers'])
                ? count($brandData['offers']) : 0;

            $trackersCount = 0;
            if (isset($brandData['offers']) && is_array($brandData['offers'])) {
                foreach ($brandData['offers'] as $offer) {
                    if (isset($offer['trackers']) && is_array($offer['trackers'])) {
                        $trackersCount += count($offer['trackers']);
                    }
                }
            }

            $localLogoUrlColumn = !empty($localLogoPath) ? $localLogoPath : null;

            $row = [
                'api_brand_id'         => $apiBrandId,
                'name'                 => $brandName,
                'slug'                 => $brandSlug,
                'status'               => $brandStatus,
                'product_types'        => $productTypes,
                'licenses'             => $licenses,
                'classification_types' => $classificationTypes,
                'top_geos'             => $topGeos,
                'offers_count'         => $offersCount,
                'trackers_count'       => $trackersCount,
                'local_logo_url'       => $localLogoUrlColumn,
                'data'                 => json_encode($brandData),
                'last_synced'          => current_time('mysql'),
            ];

            $persisted = $this->brands->upsert($row);
            if ($persisted !== false) {
                $synced++;
                $this->logger->debug(
                    'BrandSync.upsert id=' . $apiBrandId
                    . ' name="' . $brandName . '"'
                    . ' offers=' . $offersCount
                    . ' trackers=' . $trackersCount
                    . ' logo=' . ($localLogoUrlColumn ? 'cached' : 'remote')
                );
            } else {
                $errors++;
                $this->logger->error('BrandSync: upsert failed for brand ID ' . $apiBrandId);
            }

            unset($brandData, $productTypes, $licenses, $classificationTypes,
                  $topGeos, $topGeosArr, $offersCount, $trackersCount,
                  $localLogoPath, $localLogoUrlColumn, $row, $persisted);
        }

        unset($data, $body);
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }

        global $wpdb;
        $brandsTable  = $wpdb->prefix . 'dataflair_brands';
        $totalSynced  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$brandsTable} WHERE status = 'Active'");

        $partial = $budgetExhaustedOnItem !== null;

        $this->logger->info(
            'BrandSync.page_done page=' . $page . '/' . $lastPage
            . ' synced=' . $synced . ' errors=' . $errors
            . ' total_active_in_db=' . $totalSynced
            . ' partial=' . ($partial ? '1' : '0')
        );

        return [
            'success'      => true,
            'last_page'    => $lastPage,
            'synced'       => $synced,
            'errors'       => $errors,
            'total_synced' => $totalSynced,
            'total_brands' => $total,
            'partial'      => $partial,
            'next_page'    => $partial ? $page : ($page + 1),
        ];
    }

    private function deleteAllPaginated(string $table, int $batch): void
    {
        global $wpdb;
        do {
            $rows = (int) $wpdb->query("DELETE FROM {$table} LIMIT {$batch}");
        } while ($rows > 0);
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
