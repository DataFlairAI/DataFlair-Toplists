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
use DataFlair\Toplists\Admin\Handlers\ApiPreviewHandler;
use DataFlair\Toplists\Admin\Handlers\DeleteAlternativeToplistHandler;
use DataFlair\Toplists\Admin\Handlers\FetchAllBrandsHandler;
use DataFlair\Toplists\Admin\Handlers\FetchAllToplistsHandler;
use DataFlair\Toplists\Admin\Handlers\GetAlternativeToplistsHandler;
use DataFlair\Toplists\Admin\Handlers\GetAvailableGeosHandler;
use DataFlair\Toplists\Admin\Handlers\SaveAlternativeToplistHandler;
use DataFlair\Toplists\Admin\Handlers\SaveReviewUrlHandler;
use DataFlair\Toplists\Admin\Handlers\SaveSettingsHandler;
use DataFlair\Toplists\Admin\Handlers\SyncBrandsBatchHandler;
use DataFlair\Toplists\Admin\Handlers\SyncToplistsBatchHandler;
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

        return $router;
    }

    public function registerAssets(): void
    {
        (new AdminAssetsRegistrar())->register();
    }
}
