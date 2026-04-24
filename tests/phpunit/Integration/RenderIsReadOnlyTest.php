<?php
/**
 * H0 regression test: render_casino_card() and its template must be read-only.
 *
 * Sigma OOM root cause (April 2026): render_casino_card() called a theme-side
 * helper that walked the WP media-library sideload chain per card, plus it
 * auto-created CPT rows via wp_insert_post + update_post_meta on cold renders.
 * Both are forbidden in the render chain forever; reconciliation happens at
 * sync time / via `wp dataflair reconcile-reviews`.
 *
 * This test is two-part:
 *
 *   (A) Structural: scan the render_casino_card() method body + render-casino-card.php
 *       template for direct calls to any forbidden function name.
 *
 *   (B) Execution: include the template with a minimal fixture and stubs that
 *       throw on invocation. If any forbidden function fires during render,
 *       the test fails.
 */

use PHPUnit\Framework\TestCase;

class RenderIsReadOnlyTest extends TestCase
{
    /**
     * Functions render must never invoke. Each one allocates, writes, or performs IO
     * that has no business running on a public page view.
     *
     * NOTE on `set_transient` / `delete_transient`:
     * The tracker-URL transient write at render-casino-card.php:216 is a known
     * render-time write. It is NOT an OOM hotspot (tiny string payload) and is
     * out of Phase 0A H0 scope. Phase 0B H10 will relocate tracker transients
     * to sync-time and re-add them to this list.
     */
    private const FORBIDDEN_FUNCTIONS = [
        'wp_remote_get',
        'wp_remote_post',
        'wp_safe_remote_get',
        'wp_safe_remote_post',
        'wp_insert_post',
        'wp_update_post',
        'wp_handle_sideload',
        'wp_handle_upload',
        'media_sideload_image',
        'media_handle_sideload',
        'download_url',
        'wp_check_filetype',
        'wp_check_filetype_and_ext',
        'update_option',
        'update_post_meta',
        'delete_post_meta',
    ];

    /** Regex that matches any strikeodds_* sideload-ish helper. */
    private const FORBIDDEN_THEME_HELPER_PATTERN = '/strikeodds_(download|sideload|save|upload|create|insert)\w*/';

    // ── Part A — structural scan of the source ─────────────────────────────────

    public function test_render_casino_card_method_body_has_no_forbidden_calls(): void
    {
        $plugin_file = DATAFLAIR_PLUGIN_DIR . 'dataflair-toplists.php';
        $source = file_get_contents($plugin_file);
        $this->assertNotFalse($source, 'Plugin file must be readable');

        $body = $this->extractMethodBody($source, 'render_casino_card');
        $this->assertNotEmpty($body, 'render_casino_card() method body must be found in plugin file');

        foreach (self::FORBIDDEN_FUNCTIONS as $fn) {
            $pattern = '/(?<![a-zA-Z0-9_\$>])' . preg_quote($fn, '/') . '\s*\(/';
            $this->assertDoesNotMatchRegularExpression(
                $pattern,
                $body,
                "render_casino_card() must not call forbidden function `$fn` — it makes render non-read-only."
            );
        }

        $this->assertDoesNotMatchRegularExpression(
            self::FORBIDDEN_THEME_HELPER_PATTERN,
            $body,
            'render_casino_card() must not call any strikeodds_{download|sideload|save|upload|create|insert}* helper — theme coupling is forbidden at render time.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/\$this->get_or_create_review_post\s*\(/',
            $body,
            'render_casino_card() must not call get_or_create_review_post() — review reconciliation is a sync-time / CLI concern, not render.'
        );
    }

    public function test_render_template_has_no_forbidden_calls(): void
    {
        $template = DATAFLAIR_PLUGIN_DIR . 'views/frontend/casino-card.php';
        $source = file_get_contents($template);
        $this->assertNotFalse($source, 'views/frontend/casino-card.php must be readable');

        foreach (self::FORBIDDEN_FUNCTIONS as $fn) {
            $pattern = '/(?<![a-zA-Z0-9_\$>])' . preg_quote($fn, '/') . '\s*\(/';
            $this->assertDoesNotMatchRegularExpression(
                $pattern,
                $source,
                "views/frontend/casino-card.php must not call forbidden function `$fn` at render time."
            );
        }

        $this->assertDoesNotMatchRegularExpression(
            self::FORBIDDEN_THEME_HELPER_PATTERN,
            $source,
            'views/frontend/casino-card.php must not call any strikeodds_* sideload-ish helper at render time.'
        );
    }

    // ── Part B — execution-path test ───────────────────────────────────────────

    /**
     * Runs in a separate process so our forbidden-function stubs are not blocked
     * by harmless stubs that earlier tests (e.g. RenderCasinoCardReviewFallbackTest)
     * defined for the same WordPress functions.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_template_render_does_not_invoke_any_forbidden_function(): void
    {
        require_once __DIR__ . '/RenderReadOnlyStubs.php';
        RenderReadOnlyStubs::reset();
        RenderReadOnlyStubs::installForbiddenFunctionStubs(self::FORBIDDEN_FUNCTIONS);

        $item = [
            'position' => 1,
            'rating' => 4.6,
            'brand' => [
                'name' => 'Test Casino',
                'slug' => 'test-casino',
                'api_brand_id' => 777,
                'local_logo_url' => 'https://cdn.example.test/test-casino-logo.png',
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
        include DATAFLAIR_PLUGIN_DIR . 'views/frontend/casino-card.php';
        ob_end_clean();

        $this->assertSame(
            [],
            RenderReadOnlyStubs::getCalls(),
            'Template render must not trigger any forbidden function. Calls captured: ' .
            implode(', ', RenderReadOnlyStubs::getCalls())
        );
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Extract the body of a method by name from a PHP source string.
     * Matches the opening brace after `function $name(...)` and counts braces
     * to find the closing brace. Returns the substring between them.
     */
    private function extractMethodBody(string $source, string $methodName): string
    {
        $signaturePattern = '/function\s+' . preg_quote($methodName, '/') . '\s*\(/';
        if (!preg_match($signaturePattern, $source, $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        $start = $m[0][1];
        $openBrace = strpos($source, '{', $start);
        if ($openBrace === false) {
            return '';
        }

        $depth = 1;
        $i = $openBrace + 1;
        $len = strlen($source);
        while ($i < $len && $depth > 0) {
            $ch = $source[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $openBrace + 1, $i - $openBrace - 1);
                }
            }
            $i++;
        }
        return '';
    }
}
