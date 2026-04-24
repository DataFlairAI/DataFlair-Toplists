<?php
/**
 * Contract for brand-row persistence on `wp_dataflair_brands`.
 *
 * Phase 2 — extracted from god-class scattered `$wpdb` calls. Exposes the
 * key lookup + batch + per-row update methods used by sync and render. Bulk
 * methods (`findManyByApiBrandIds`, `findReviewPostsByBrandIds`) are the
 * Phase 0B H7/H8 batch queries extracted verbatim.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Database;

interface BrandsRepositoryInterface
{
    /**
     * Look up a single brand row by its upstream DataFlair brand ID.
     *
     * @return array<string, mixed>|null
     */
    public function findByApiBrandId(int $api_brand_id): ?array;

    /**
     * Look up a single brand row by slug.
     *
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array;

    /**
     * Look up a single brand row by display name.
     * Used by the `render_casino_card` legacy cascade (Phase 4 extraction)
     * when neither api_brand_id nor slug resolves a row.
     *
     * @return array<string, mixed>|null
     */
    public function findByName(string $name): ?array;

    /**
     * Batch-fetch brand rows by upstream DataFlair brand IDs.
     * Returns a map keyed by `api_brand_id`. Used by `render_toplist_table()` (Phase 0B H7).
     *
     * @param int[] $api_brand_ids
     * @return array<int, array<string, mixed>>
     */
    public function findManyByApiBrandIds(array $api_brand_ids): array;

    /**
     * Batch-fetch review-post IDs linked to the given brand IDs via postmeta.
     * Returns a map keyed by `api_brand_id` → published review post ID. Phase 0B H8.
     *
     * @param int[] $api_brand_ids
     * @return array<int, int>
     */
    public function findReviewPostsByApiBrandIds(array $api_brand_ids): array;

    /**
     * Persist a brand row (insert or update by unique `api_brand_id`).
     * Returns the row ID on success, or `false` on failure.
     *
     * @param array<string, mixed> $row
     * @return int|false
     */
    public function upsert(array $row);

    /**
     * Update the cached `local_logo_url` column for a single brand.
     */
    public function updateLocalLogoUrl(int $id, string $local_url): bool;

    /**
     * Update the cached `cached_review_post_id` column for a single brand.
     */
    public function updateCachedReviewPostId(int $id, int $review_post_id): bool;

    /**
     * Persist a custom `review_url_override` for a brand addressed by
     * upstream DataFlair brand ID. Passing `null` clears the override.
     */
    public function updateReviewUrlOverrideByApiBrandId(int $api_brand_id, ?string $url): bool;
}
