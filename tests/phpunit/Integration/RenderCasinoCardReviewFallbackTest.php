<?php
/**
 * Regression test for review pros fallback in render-casino-card.php.
 *
 * Scenario:
 * - Slug lookup returns a draft review first (must be ignored)
 * - A published review exists for the same _review_brand_id
 * - Block override exists but has empty pros/cons arrays
 *
 * Expected:
 * - Card features use _review_pros from the published review post.
 */

use PHPUnit\Framework\TestCase;

if (!function_exists('esc_html')) {
    function esc_html($value) { return (string) $value; }
}
if (!function_exists('esc_attr')) {
    function esc_attr($value) { return (string) $value; }
}
if (!function_exists('esc_url')) {
    function esc_url($value) { return (string) $value; }
}
if (!function_exists('sanitize_title')) {
    function sanitize_title($value) {
        $value = strtolower(trim((string) $value));
        $value = str_replace('.', '-', $value);
        $value = preg_replace('/[^a-z0-9\-]+/', '-', $value);
        return trim((string) $value, '-');
    }
}
if (!function_exists('home_url')) {
    function home_url($path = '') { return 'https://example.test' . $path; }
}
if (!function_exists('post_type_exists')) {
    function post_type_exists($post_type) { return $post_type === 'review'; }
}
if (!function_exists('set_transient')) {
    function set_transient($key, $value, $expiration) { return true; }
}
if (!function_exists('wp_reset_postdata')) {
    function wp_reset_postdata() { return; }
}
if (!function_exists('get_page_by_path')) {
    function get_page_by_path($path, $output = OBJECT, $post_type = 'post') {
        $pages = $GLOBALS['df_test_pages_by_slug'] ?? [];
        return $pages[$path] ?? null;
    }
}
if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key, $single = true) {
        $meta = $GLOBALS['df_test_post_meta'] ?? [];
        return $meta[$post_id][$key] ?? '';
    }
}

if (!class_exists('WP_Post')) {
    class WP_Post {
        public int $ID;
        public string $post_status;
        public string $post_modified;

        public function __construct(int $id, string $status, string $modified) {
            $this->ID = $id;
            $this->post_status = $status;
            $this->post_modified = $modified;
        }
    }
}

if (!class_exists('WP_Query')) {
    class WP_Query {
        public array $posts = [];

        public function __construct(array $args = []) {
            $all_posts = $GLOBALS['df_test_all_review_posts'] ?? [];
            $post_meta = $GLOBALS['df_test_post_meta'] ?? [];

            $target_brand_id = '';
            if (!empty($args['meta_query'][0]['value'])) {
                $target_brand_id = (string) $args['meta_query'][0]['value'];
            }

            $status_filter = $args['post_status'] ?? null;
            $matches = [];

            foreach ($all_posts as $post) {
                if ($status_filter && $post->post_status !== $status_filter) {
                    continue;
                }

                $brand_id = (string) ($post_meta[$post->ID]['_review_brand_id'] ?? '');
                if ($target_brand_id !== '' && $brand_id !== $target_brand_id) {
                    continue;
                }

                $matches[] = $post;
            }

            usort($matches, static function ($a, $b) {
                return strcmp($b->post_modified, $a->post_modified);
            });

            $limit = isset($args['posts_per_page']) ? (int) $args['posts_per_page'] : 0;
            if ($limit > 0) {
                $matches = array_slice($matches, 0, $limit);
            }

            $this->posts = $matches;
        }

        public function have_posts(): bool {
            return !empty($this->posts);
        }
    }
}

class RenderCasinoCardReviewFallbackTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['df_test_pages_by_slug'] = [];
        $GLOBALS['df_test_post_meta'] = [];
        $GLOBALS['df_test_all_review_posts'] = [];
    }

    public function test_draft_slug_is_ignored_and_published_brand_review_pros_are_used(): void {
        // Draft review with exact slug match (should be ignored).
        $draft_review = new WP_Post(2262, 'draft', '2026-03-01 10:00:00');

        // Published reviews for same _review_brand_id (newest modified should win).
        $published_old = new WP_Post(2100, 'publish', '2026-02-10 09:00:00');
        $published_new = new WP_Post(2181, 'publish', '2026-03-10 09:00:00');

        $GLOBALS['df_test_pages_by_slug'] = [
            '1xbet-sportsbook' => $draft_review,
        ];

        $GLOBALS['df_test_all_review_posts'] = [$draft_review, $published_old, $published_new];
        $GLOBALS['df_test_post_meta'] = [
            2262 => ['_review_brand_id' => '1469', '_review_pros' => ''],
            2100 => ['_review_brand_id' => '1469', '_review_pros' => 'Old Pro 1|Old Pro 2'],
            2181 => ['_review_brand_id' => '1469', '_review_pros' => 'Published Pro 1|Published Pro 2|Published Pro 3'],
        ];

        $item = [
            'position' => 1,
            'rating' => 4.8,
            'brand' => [
                'name' => '1xBet Sportsbook',
                'slug' => '1xbet-sportsbook',
                'api_brand_id' => 1469,
            ],
            'offer' => [
                'offerText' => 'Bonus',
                'trackers' => [],
            ],
        ];

        // Empty block override must not block review-meta fallback.
        $pros_cons_data = [
            'casino-1-1xbet-sportsbook' => ['pros' => [], 'cons' => []],
        ];

        ob_start();
        include DATAFLAIR_PLUGIN_DIR . 'includes/render-casino-card.php';
        ob_end_clean();

        $this->assertSame(
            ['Published Pro 1', 'Published Pro 2', 'Published Pro 3'],
            array_values($features)
        );
    }
}

