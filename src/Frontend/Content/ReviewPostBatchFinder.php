<?php
/**
 * Phase 9.9 — H8 batched review-post lookup.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Frontend\Content;

use DataFlair\Toplists\Database\BrandsRepositoryInterface;

/**
 * Batched review-post lookup. Given a list of api_brand_ids, returns
 * `[api_brand_id => post_id]` for brands whose review CPT is published
 * but does not yet have `cached_review_post_id` populated on the brands
 * table.
 *
 * Defensive backstop only — normal render paths read
 * `cached_review_post_id` directly and never hit this code. Phase 0B H8.
 */
final class ReviewPostBatchFinder
{
    public function __construct(
        private readonly BrandsRepositoryInterface $brandsRepo
    ) {
    }

    /**
     * @param int[] $brand_ids
     * @return array<int,int>
     */
    public function findByApiBrandIds(array $brand_ids): array
    {
        return $this->brandsRepo->findReviewPostsByApiBrandIds($brand_ids);
    }
}
