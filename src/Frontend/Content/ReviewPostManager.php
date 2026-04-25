<?php
/**
 * Phase 9.9 — On-demand review-CPT manager.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Frontend\Content;

use DataFlair\Toplists\Logging\LoggerInterface;
use DataFlair\Toplists\Logging\NullLogger;
use WP_Post;

/**
 * Get or create the review CPT for a casino brand. Auto-creates a draft
 * review when one does not already exist.
 *
 * **Render-time invariant (Phase 0A):** this class is NEVER called from the
 * card-render path. It is invoked only from sync, WP-CLI reconcile, and admin
 * paths. {@see RenderIsReadOnlyTest} pins this — flipping the read-only
 * contract crashes Sigma under 1 GB / 30 s.
 */
final class ReviewPostManager
{
    public function __construct(
        private readonly ReviewPostFinder $finder,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
    }

    /**
     * @param array<string,mixed> $brand Brand data from the API.
     * @param array<string,mixed> $item  Full toplist item payload.
     * @return int|false Post ID of the review, or false on failure.
     */
    public function getOrCreate(array $brand, array $item): int|false
    {
        if (!post_type_exists('review')) {
            $this->logger->error('DataFlair: Review post type not registered');
            return false;
        }

        $brand_slug = !empty($brand['slug']) ? $brand['slug'] : sanitize_title($brand['name'] ?? '');
        $brand_name = !empty($brand['name']) ? $brand['name'] : 'Unknown Casino';

        // Exact slug match: only trust it if published. Otherwise a plugin-created draft at
        // {slug} blocks discovery of the live review at {slug}-india (same _review_brand_id).
        $existing_review = get_page_by_path($brand_slug, OBJECT, 'review');
        if ($existing_review instanceof WP_Post && 'publish' === $existing_review->post_status) {
            return $existing_review->ID;
        }

        $by_meta = $this->finder->findByBrandMeta($brand);
        if ($by_meta instanceof WP_Post && 'publish' === $by_meta->post_status) {
            return $by_meta->ID;
        }

        if ($existing_review instanceof WP_Post) {
            return $existing_review->ID;
        }

        if ($by_meta instanceof WP_Post) {
            return $by_meta->ID;
        }

        $review_data = [
            'post_title'   => $brand_name . ' Review',
            'post_name'    => $brand_slug,
            'post_content' => '',
            'post_status'  => 'draft',
            'post_type'    => 'review',
            'post_author'  => get_current_user_id() ?: 1,
        ];

        $review_id = wp_insert_post($review_data);

        if (is_wp_error($review_id)) {
            $this->logger->error('DataFlair: Failed to create review post: ' . $review_id->get_error_message());
            return false;
        }

        $brand_id_for_meta = !empty($brand['id'])
            ? (int) $brand['id']
            : (!empty($brand['api_brand_id']) ? (int) $brand['api_brand_id'] : 0);
        if ($brand_id_for_meta > 0) {
            update_post_meta($review_id, '_review_brand_id', $brand_id_for_meta);
        }

        $logo_url = '';
        if (!empty($brand['logo'])) {
            if (is_array($brand['logo'])) {
                $logo_url = $brand['logo']['rectangular']
                    ?? $brand['logo']['square']
                    ?? $brand['logo']['url']
                    ?? '';
            } else {
                $logo_url = $brand['logo'];
            }
        }

        update_post_meta($review_id, '_review_brand_name', $brand_name);
        update_post_meta($review_id, '_review_logo', $logo_url);
        update_post_meta($review_id, '_review_url', !empty($item['offer']['tracking_url']) ? $item['offer']['tracking_url'] : '');
        update_post_meta(
            $review_id,
            '_review_rating',
            !empty($item['rating']) ? $item['rating'] : (!empty($brand['rating']) ? $brand['rating'] : '')
        );
        update_post_meta($review_id, '_review_bonus', !empty($item['offer']['offerText']) ? $item['offer']['offerText'] : '');

        $payments = [];
        if (!empty($item['paymentMethods'])) {
            $payments = is_array($item['paymentMethods']) ? $item['paymentMethods'] : explode(',', $item['paymentMethods']);
        } elseif (!empty($brand['paymentMethods'])) {
            $payments = is_array($brand['paymentMethods']) ? $brand['paymentMethods'] : explode(',', $brand['paymentMethods']);
        }
        update_post_meta($review_id, '_review_payments', implode(', ', $payments));

        $licenses = [];
        if (!empty($brand['licenses'])) {
            $licenses = is_array($brand['licenses']) ? $brand['licenses'] : explode(',', $brand['licenses']);
        }
        update_post_meta($review_id, '_review_licenses', implode(', ', $licenses));

        $this->logger->info('DataFlair: Auto-created draft review post #' . $review_id . ' for ' . $brand_name);

        return $review_id;
    }
}
