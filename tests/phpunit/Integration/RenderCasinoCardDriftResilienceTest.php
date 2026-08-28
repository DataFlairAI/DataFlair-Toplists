<?php
/**
 * API Contract Safety P4 — the card template must survive contract drift.
 *
 * Feeds the template an item whose fields have been hostilely retyped the
 * way backend drift would (strings become arrays, arrays become strings,
 * brand object missing) and asserts the render completes with NO PHP
 * notice, warning, or TypeError. This mirrors the Sigma production setup
 * where WP_DEBUG_DISPLAY prints every notice into the page markup.
 */

use PHPUnit\Framework\TestCase;

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}
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
// These stubs are shared process-wide with RenderCasinoCardReviewFallbackTest
// (first-loaded file wins), so they MUST mirror that file's behaviour exactly.
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

class RenderCasinoCardDriftResilienceTest extends TestCase {
    /** @var array<int, string> */
    private array $phpIssues = [];

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['df_test_pages_by_slug'] = [];
        $GLOBALS['df_test_post_meta'] = [];
        $GLOBALS['df_test_all_review_posts'] = [];

        $this->phpIssues = [];
        set_error_handler(function (int $errno, string $errstr, string $errfile = '', int $errline = 0): bool {
            // Only issues raised from the template under test matter.
            if (str_contains($errfile, 'casino-card.php')) {
                $this->phpIssues[] = "[$errno] $errstr @ $errfile:$errline";
            }
            return true;
        });
    }

    protected function tearDown(): void {
        restore_error_handler();
        parent::tearDown();
    }

    private function renderCard(array $item): string {
        $pros_cons_data = [];
        ob_start();
        try {
            include DATAFLAIR_PLUGIN_DIR . 'views/frontend/casino-card.php';
        } finally {
            $html = (string) ob_get_clean();
        }
        return $html;
    }

    public function test_hostile_retypes_render_without_notices_or_fatals(): void {
        $item = [
            'position' => ['nested' => 'array'],       // scalar → array
            'rating'   => ['value' => 4.8],            // float → object
            'features' => 'no longer an array',        // array → string
            'pros'     => [['nested'], 'Real pro', null], // entries retyped
            'cons'     => 'no longer an array',
            'games_count' => ['count' => 3000],
            'reviewer'    => ['name' => 'Jane'],
            'brand' => [
                'name'   => ['weird' => 'object'],     // string → object
                'slug'   => ['also' => 'object'],
                'rating' => [4.8],
                'licenses' => 'MGA',                   // legit string form
                'payment_methods' => 'Visa, Mastercard', // array → string
            ],
            'offer' => [
                'offerText'  => ['renamed' => 'shape'],
                'bonus_code' => ['no' => 'longer a string'],
                'bonus_wagering_requirement' => ['x'],
                'minimum_deposit' => ['x'],
                'payout_time' => ['x'],
                'max_payout' => ['x'],
                'trackers'   => 'https://not-an-array.example',
                'tracking_url' => ['x'],
                'url' => ['x'],
            ],
        ];

        $html = $this->renderCard($item);

        $this->assertSame([], $this->phpIssues, "Template emitted PHP issues:\n" . implode("\n", $this->phpIssues));
        $this->assertNotSame('', $html, 'card must still render a degraded but valid shell');
    }

    public function test_missing_brand_and_offer_objects_render_without_notices(): void {
        $html = $this->renderCard(['position' => 1]);

        $this->assertSame([], $this->phpIssues, "Template emitted PHP issues:\n" . implode("\n", $this->phpIssues));
        $this->assertNotSame('', $html);
    }

    public function test_healthy_item_still_renders_all_fields(): void {
        $item = [
            'position' => 1,
            'rating'   => 4.8,
            'brand'    => [
                'name' => 'Betway',
                'slug' => 'betway',
            ],
            'offer'    => [
                'offerText'  => '100% up to $500',
                'bonus_code' => 'WELCOME',
                'trackers'   => [
                    ['trackerLink' => 'https://t.example.com/c/1', 'campaignName' => 'Main'],
                ],
            ],
        ];

        $html = $this->renderCard($item);

        $this->assertSame([], $this->phpIssues);
        $this->assertStringContainsString('Betway', $html);
        $this->assertStringContainsString('100% up to $500', $html);
        $this->assertStringContainsString('WELCOME', $html);
    }
}
