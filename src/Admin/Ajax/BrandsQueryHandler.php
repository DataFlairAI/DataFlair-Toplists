<?php
/**
 * Phase 9.6 (admin UX redesign) — Server-side query for the Brands list.
 *
 * Consumes BrandsQuery DTO from the POST payload, delegates to
 * BrandsRepository::findPaginated(), and returns rows + total + filter
 * facets so brands.js can re-render the table without a full page reload.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Ajax;

use DataFlair\Toplists\Admin\AjaxHandlerInterface;
use DataFlair\Toplists\Database\BrandsQuery;
use DataFlair\Toplists\Database\BrandsRepositoryInterface;

final class BrandsQueryHandler implements AjaxHandlerInterface
{
    public function __construct(private BrandsRepositoryInterface $repo) {}

    public function handle(array $request): array
    {
        $query = BrandsQuery::fromArray($request);
        $page  = $this->repo->findPaginated($query);

        return [
            'success' => true,
            'data'    => [
                'rows'    => $page->rows,
                'total'   => $page->total,
                'pages'   => $page->pageCount(),
                'page'    => $query->page,
                'per_page' => $query->perPage,
                'filter_options' => [
                    'licenses'      => $this->repo->collectDistinctValuesForFilter('licenses'),
                    'geos'          => $this->repo->collectDistinctValuesForFilter('top_geos'),
                    'payments'      => $this->repo->collectDistinctValuesForFilter('payments'),
                    'product_types' => $this->repo->collectDistinctValuesForFilter('product_types'),
                ],
            ],
        ];
    }
}
