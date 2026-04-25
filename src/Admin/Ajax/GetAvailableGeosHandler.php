<?php
/**
 * List the geos used by every known toplist. Feeds the alternative-toplist
 * admin dropdown. Delegates to ToplistsRepository::collectGeoNames().
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Ajax;

use DataFlair\Toplists\Admin\AjaxHandlerInterface;
use DataFlair\Toplists\Database\ToplistsRepositoryInterface;

final class GetAvailableGeosHandler implements AjaxHandlerInterface
{
    public function __construct(private ToplistsRepositoryInterface $repo) {}

    public function handle(array $request): array
    {
        return ['success' => true, 'data' => ['geos' => $this->repo->collectGeoNames()]];
    }
}
