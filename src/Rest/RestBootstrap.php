<?php
/**
 * REST-side bootstrap. Wires the three controllers + RestRouter from one seam.
 *
 * Phase 6 — counterpart of Phase 5's AdminBootstrap. Instantiated lazily by
 * the god-class at `rest_api_init` time; a future phase (singleton removal)
 * will move instantiation into the plugin Container.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Rest;

use DataFlair\Toplists\Database\ToplistsRepositoryInterface;
use DataFlair\Toplists\Logging\LoggerInterface;
use DataFlair\Toplists\Rest\Controllers\CasinosController;
use DataFlair\Toplists\Rest\Controllers\HealthController;
use DataFlair\Toplists\Rest\Controllers\ToplistsController;

final class RestBootstrap
{
    /**
     * @param \Closure(array<int,array<string,mixed>>):array<string,array<int|string,object>> $prefetchBrandMetas
     * @param \Closure(array<string,mixed>,array<string,array<int|string,object>>):?object   $lookupBrandMeta
     */
    public function __construct(
        private LoggerInterface $logger,
        private ToplistsRepositoryInterface $toplists_repo,
        private \Closure $prefetchBrandMetas,
        private \Closure $lookupBrandMeta
    ) {}

    public function boot(): RestRouter
    {
        $toplists = new ToplistsController($this->toplists_repo, $this->logger);
        $casinos  = new CasinosController(
            $this->toplists_repo,
            $this->prefetchBrandMetas,
            $this->lookupBrandMeta,
            $this->logger
        );
        $health = new HealthController($this->toplists_repo);

        return new RestRouter($toplists, $casinos, $health);
    }
}
