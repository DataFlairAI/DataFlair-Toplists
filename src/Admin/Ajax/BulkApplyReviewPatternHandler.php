<?php
/**
 * Phase 9.6 (admin UX redesign) — Bulk-apply a review-URL pattern to
 * selected brands.
 *
 * Input: { api_brand_ids: int[], pattern: string }
 * The pattern MUST contain the `{slug}` token, which is replaced per-brand.
 * Example: /reviews/{slug}/
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Ajax;

use DataFlair\Toplists\Admin\AjaxHandlerInterface;
use DataFlair\Toplists\Database\BrandsRepositoryInterface;

final class BulkApplyReviewPatternHandler implements AjaxHandlerInterface
{
    public function __construct(private BrandsRepositoryInterface $repo) {}

    public function handle(array $request): array
    {
        $pattern = isset($request['pattern']) ? trim((string) $request['pattern']) : '';
        if (strpos($pattern, '{slug}') === false) {
            return ['success' => false, 'data' => ['message' => 'Pattern must contain the {slug} token.']];
        }

        $raw_ids = isset($request['api_brand_ids']) ? (array) $request['api_brand_ids'] : [];
        $ids     = array_values(array_filter(array_map('intval', $raw_ids)));
        if (empty($ids)) {
            return ['success' => false, 'data' => ['message' => 'No brand IDs provided.']];
        }

        $brands = $this->repo->findManyByApiBrandIds($ids);
        $updated = 0;
        foreach ($brands as $api_id => $row) {
            $slug = isset($row['slug']) ? (string) $row['slug'] : '';
            if ($slug === '') {
                continue;
            }
            $url = str_replace('{slug}', $slug, $pattern);
            if ($this->repo->updateReviewUrlOverrideByApiBrandId((int) $api_id, $url)) {
                $updated++;
            }
        }

        return ['success' => true, 'data' => ['updated' => $updated]];
    }
}
