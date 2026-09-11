<?php
/**
 * Contract for building the brands-list API URL. Exists so
 * BrandSyncService can depend on a typed collaborator instead of an
 * untyped `callable` - a hand-written adapter closure around this method
 * silently dropped a newly-added parameter once already (see
 * BrandSyncServiceTest's regression coverage), because PHP doesn't error
 * when a closure is invoked with more arguments than it declares. A typed
 * method call makes that mismatch a hard error instead.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Http;

interface BrandsApiUrlBuilderInterface
{
    /**
     * @param int[]|null $ids When given, restricts the page to just these
     *                        api_brand_ids (still paginated) instead of the
     *                        full catalog - used by "re-sync selected".
     */
    public function buildPageUrl(int $page, int $perPage = 25, ?array $ids = null): string;
}
