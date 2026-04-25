<?php
/**
 * Admin-side bootstrap. Wires the AjaxRouter, admin pages, and asset
 * registrar to WordPress from a single seam. Instantiated by the god-class
 * at init-hooks time in Phase 5; a future phase (singleton removal) will
 * move this to a proper Container.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin;

use DataFlair\Toplists\Admin\Assets\AdminAssetsRegistrar;
use DataFlair\Toplists\Admin\Ajax\ApiPreviewHandler;
use DataFlair\Toplists\Admin\Ajax\BrandDetailsHandler;
use DataFlair\Toplists\Admin\Ajax\BrandsQueryHandler;
use DataFlair\Toplists\Admin\Ajax\BulkApplyReviewPatternHandler;
use DataFlair\Toplists\Admin\Ajax\BulkDisableBrandsHandler;
use DataFlair\Toplists\Admin\Ajax\BulkResyncBrandsHandler;
use DataFlair\Toplists\Admin\Ajax\ApiHealthHandler;
use DataFlair\Toplists\Admin\Ajax\BulkDeleteToplistsHandler;
use DataFlair\Toplists\Admin\Ajax\BulkResyncToplistsHandler;
use DataFlair\Toplists\Admin\Ajax\LogsDownloadHandler;
use DataFlair\Toplists\Admin\Ajax\LogsTailHandler;
use DataFlair\Toplists\Admin\Ajax\RunAllTestsHandler;
use DataFlair\Toplists\Admin\Ajax\TestApiConnectionHandler;
use DataFlair\Toplists\Admin\Ajax\ToplistAccordionDetailsHandler;
use DataFlair\Toplists\Admin\Ajax\ToplistRawJsonHandler;
use DataFlair\Toplists\Admin\Ajax\ToplistUsageHandler;
use DataFlair\Toplists\Admin\Ajax\RunTestHandler;
use DataFlair\Toplists\Admin\Pages\Tools\TestsRunner;
use DataFlair\Toplists\Admin\Ajax\DeleteAlternativeToplistHandler;
use DataFlair\Toplists\Admin\Ajax\FetchAllBrandsHandler;
use DataFlair\Toplists\Admin\Ajax\FetchAllToplistsHandler;
use DataFlair\Toplists\Admin\Ajax\GetAlternativeToplistsHandler;
use DataFlair\Toplists\Admin\Ajax\GetAvailableGeosHandler;
use DataFlair\Toplists\Admin\Ajax\SaveAlternativeToplistHandler;
use DataFlair\Toplists\Admin\Ajax\SaveReviewUrlHandler;
use DataFlair\Toplists\Admin\Ajax\SaveSettingsHandler;
use DataFlair\Toplists\Admin\Ajax\SyncBrandsBatchHandler;
use DataFlair\Toplists\Admin\Ajax\SyncToplistsBatchHandler;
use DataFlair\Toplists\Database\AlternativesRepositoryInterface;
use DataFlair\Toplists\Database\BrandsRepositoryInterface;
use DataFlair\Toplists\Database\ToplistsRepositoryInterface;
use DataFlair\Toplists\Http\HttpClientInterface;
use DataFlair\Toplists\Logging\LoggerInterface;
use DataFlair\Toplists\Sync\BrandSyncServiceInterface;
use DataFlair\Toplists\Sync\ToplistSyncServiceInterface;

final class AdminBootstrap
{
    public function __construct(
        private LoggerInterface $logger,
        private BrandsRepositoryInterface $brands_repo,
        private ToplistsRepositoryInterface $toplists_repo,
        private AlternativesRepositoryInterface $alternatives_repo,
        private HttpClientInterface $api_client,
        private ToplistSyncServiceInterface $toplist_sync,
        private BrandSyncServiceInterface $brand_sync,
        private \Closure $apiBaseUrlResolver
    ) {}

    /**
     * Register every AJAX route with the router.
     *
     * Each entry: action → [handler instance, nonce action, capability].
     */
    public function boot(): AjaxRouter
    {
        $router = new AjaxRouter($this->logger);

        $router->register(
            'dataflair_save_settings',
            new SaveSettingsHandler(),
            'dataflair_save_settings'
        );
        $router->register(
            'dataflair_fetch_all_toplists',
            new FetchAllToplistsHandler(),
            'dataflair_fetch_all_toplists'
        );
        $router->register(
            'dataflair_sync_toplists_batch',
            new SyncToplistsBatchHandler($this->toplist_sync),
            'dataflair_sync_toplists_batch'
        );
        $router->register(
            'dataflair_fetch_all_brands',
            new FetchAllBrandsHandler(),
            'dataflair_fetch_all_brands'
        );
        $router->register(
            'dataflair_sync_brands_batch',
            new SyncBrandsBatchHandler($this->brand_sync),
            'dataflair_sync_brands_batch'
        );
        $router->register(
            'dataflair_get_alternative_toplists',
            new GetAlternativeToplistsHandler($this->alternatives_repo),
            'dataflair_save_settings'
        );
        $router->register(
            'dataflair_save_alternative_toplist',
            new SaveAlternativeToplistHandler($this->alternatives_repo),
            'dataflair_save_settings'
        );
        $router->register(
            'dataflair_delete_alternative_toplist',
            new DeleteAlternativeToplistHandler($this->alternatives_repo),
            'dataflair_save_settings'
        );
        $router->register(
            'dataflair_get_available_geos',
            new GetAvailableGeosHandler($this->toplists_repo),
            'dataflair_save_settings'
        );
        $router->register(
            'dataflair_api_preview',
            new ApiPreviewHandler($this->api_client, $this->apiBaseUrlResolver),
            'dataflair_api_preview'
        );
        $router->register(
            'dataflair_save_review_url',
            new SaveReviewUrlHandler($this->brands_repo),
            'dataflair_save_review_url'
        );
        $router->register(
            'dataflair_brands_query',
            new BrandsQueryHandler($this->brands_repo),
            'dataflair_brands_query'
        );
        $router->register(
            'dataflair_brand_details',
            new BrandDetailsHandler($this->brands_repo),
            'dataflair_brand_details'
        );
        $router->register(
            'dataflair_bulk_apply_review_pattern',
            new BulkApplyReviewPatternHandler($this->brands_repo),
            'dataflair_bulk_apply_review_pattern'
        );
        $router->register(
            'dataflair_bulk_disable_brands',
            new BulkDisableBrandsHandler($this->brands_repo),
            'dataflair_bulk_disable_brands'
        );
        $router->register(
            'dataflair_bulk_resync_brands',
            new BulkResyncBrandsHandler(),
            'dataflair_bulk_resync_brands'
        );

        $runner = new TestsRunner();
        $router->register(
            'dataflair_run_test',
            new RunTestHandler($runner),
            'dataflair_run_test'
        );
        $router->register(
            'dataflair_run_all_tests',
            new RunAllTestsHandler($runner),
            'dataflair_run_all_tests'
        );
        $router->register(
            'dataflair_logs_tail',
            new LogsTailHandler(),
            'dataflair_logs_tail'
        );
        $router->register(
            'dataflair_logs_download',
            new LogsDownloadHandler(),
            'dataflair_logs_download'
        );

        // Phase 4 handlers.
        $router->register(
            'dataflair_api_health',
            new ApiHealthHandler(),
            'dataflair_api_health'
        );
        $router->register(
            'dataflair_toplist_usage',
            new ToplistUsageHandler(),
            'dataflair_toplist_usage'
        );
        $router->register(
            'dataflair_test_api_connection',
            new TestApiConnectionHandler(),
            'dataflair_test_api_connection'
        );
        $router->register(
            'dataflair_bulk_resync_toplists',
            new BulkResyncToplistsHandler($this->toplist_sync),
            'dataflair_bulk_resync_toplists'
        );
        $router->register(
            'dataflair_bulk_delete_toplists',
            new BulkDeleteToplistsHandler($this->toplists_repo),
            'dataflair_bulk_delete_toplists'
        );
        $router->register(
            'dataflair_toplist_accordion_details',
            new ToplistAccordionDetailsHandler($this->toplists_repo, $this->brands_repo),
            'dataflair_toplist_accordion_details'
        );
        $router->register(
            'dataflair_toplist_raw_json',
            new ToplistRawJsonHandler($this->toplists_repo),
            'dataflair_toplist_raw_json'
        );

        return $router;
    }

    public function registerAssets(): void
    {
        (new AdminAssetsRegistrar())->register();
    }
}
