<?php
/**
 * H0 regression test: render_casino_card uses the pre-computed `local_logo_url`
 * column produced at sync time and never touches the network.
 *
 * Sigma OOM root cause (April 2026): the render chain fell through to a
 * theme-side helper that sideloaded the remote logo on demand, which triggered
 * wp_check_filetype_and_ext() → finfo_file() under a 1 GB memory limit. The
 * fix is to pre-compute the absolute logo URL at sync time, store it in a
 * new `local_logo_url` column on wp_dataflair_brands, and have render just
 * read that column.
 *
 * This test seeds a brand with `local_logo_url` set, renders the card, and
 * asserts (a) the emitted <img src="…"> matches the column value verbatim,
 * and (b) no HTTP / media-sideload function was invoked during render.
 *
 * @runInSeparateProcess
 * @preserveGlobalState disabled
 */

use PHPUnit\Framework\TestCase;

class CasinoCardUsesPrecomputedLogoTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_render_uses_local_logo_url_column_and_makes_no_http_calls(): void
    {
        require_once __DIR__ . '/RenderReadOnlyStubs.php';
        RenderReadOnlyStubs::reset();

        $precomputed_logo = 'https://cdn.example.test/test-casino-logo.png';

        $item = [
            'position' => 1,
            'rating' => 4.6,
            'brand' => [
                'name' => 'Test Casino',
                'slug' => 'test-casino',
                'api_brand_id' => 777,
                // The new column introduced in v1.10.8 for Phase 0A:
                'local_logo_url' => $precomputed_logo,
            ],
            'offer' => [
                'offerText' => '100% up to $500',
                'trackers' => [],
            ],
        ];

        $review_url = 'https://example.test/reviews/test-casino/';
        $dataflair_review_url_is_admin_override = false;
        $dataflair_review_cpt_is_published = false;
        $pros_cons_data = [];
        $toplist_id = 1;
        $customizations = [];

        ob_start();
        include DATAFLAIR_PLUGIN_DIR . 'includes/render-casino-card.php';
        $html = ob_get_clean();

        $this->assertStringContainsString(
            'src="' . $precomputed_logo . '"',
            $html,
            'Rendered card must use the pre-computed local_logo_url verbatim ' .
            'rather than re-deriving the logo URL at render time.'
        );

        $this->assertSame(
            [],
            RenderReadOnlyStubs::getCalls(),
            'Rendering a card with a precomputed logo URL must not invoke any ' .
            'HTTP or media-sideload function. Observed calls: ' .
            implode(', ', RenderReadOnlyStubs::getCalls())
        );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_render_falls_back_to_legacy_local_logo_when_new_column_is_empty(): void
    {
        require_once __DIR__ . '/RenderReadOnlyStubs.php';
        RenderReadOnlyStubs::reset();

        $legacy_logo = 'https://cdn.example.test/legacy-test-casino-logo.png';

        $item = [
            'position' => 2,
            'rating' => 4.2,
            'brand' => [
                'name' => 'Legacy Casino',
                'slug' => 'legacy-casino',
                'api_brand_id' => 888,
                // `local_logo_url` intentionally absent — row not yet backfilled.
                'local_logo' => $legacy_logo,
            ],
            'offer' => [
                'offerText' => '200% up to $1000',
                'trackers' => [],
            ],
        ];

        $review_url = 'https://example.test/reviews/legacy-casino/';
        $dataflair_review_url_is_admin_override = false;
        $dataflair_review_cpt_is_published = false;
        $pros_cons_data = [];
        $toplist_id = 1;
        $customizations = [];

        ob_start();
        include DATAFLAIR_PLUGIN_DIR . 'includes/render-casino-card.php';
        $html = ob_get_clean();

        $this->assertStringContainsString(
            'src="' . $legacy_logo . '"',
            $html,
            'When local_logo_url is absent the template must fall back to the ' .
            'legacy local_logo key — backfill happens lazily via ' .
            '`wp dataflair reconcile-reviews`.'
        );

        $this->assertSame(
            [],
            RenderReadOnlyStubs::getCalls(),
            'Falling back to the legacy logo column must still be read-only.'
        );
    }
}
