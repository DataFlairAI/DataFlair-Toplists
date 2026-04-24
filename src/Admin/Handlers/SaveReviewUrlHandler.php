<?php
/**
 * Persist a per-brand review-URL override (or clear it).
 * Delegates to BrandsRepository::updateReviewUrlOverrideByApiBrandId()
 * added in Phase 5.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Handlers;

use DataFlair\Toplists\Admin\AjaxHandlerInterface;
use DataFlair\Toplists\Database\BrandsRepositoryInterface;

final class SaveReviewUrlHandler implements AjaxHandlerInterface
{
    public function __construct(private BrandsRepositoryInterface $repo) {}

    public function handle(array $request): array
    {
        $brand_id   = isset($request['brand_id']) ? (int) $request['brand_id'] : 0;
        $review_url = isset($request['review_url']) ? sanitize_text_field((string) $request['review_url']) : '';

        if ($brand_id <= 0) {
            return ['success' => false, 'data' => ['message' => 'Invalid brand ID']];
        }

        $this->repo->updateReviewUrlOverrideByApiBrandId($brand_id, $review_url !== '' ? $review_url : null);
        return ['success' => true, 'data' => []];
    }
}
