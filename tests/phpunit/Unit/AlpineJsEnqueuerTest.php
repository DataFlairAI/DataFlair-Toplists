<?php
/**
 * Phase 9.8 — Pins AlpineJsEnqueuer detection logic.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Frontend\Assets;

use DataFlair\Toplists\Frontend\Assets\AlpineDeferAttribute;
use DataFlair\Toplists\Frontend\Assets\AlpineJsEnqueuer;
use DataFlair\Toplists\Frontend\Assets\WidgetShortcodeDetector;
use DataFlair\Toplists\Tests\FrontendAssets\FrontendAssetsStubs as S;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FrontendAssetsTestStubs.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Frontend/Assets/AlpineDeferAttribute.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Frontend/Assets/WidgetShortcodeDetector.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Frontend/Assets/AlpineJsEnqueuer.php';

final class AlpineJsEnqueuerTest extends TestCase
{
    protected function setUp(): void
    {
        S::reset();
        AlpineJsEnqueuer::resetForTests();
        WidgetShortcodeDetector::resetForTests();

        // Reset globals that may have been mutated by previous tests
        global $post, $wp_query, $wp_scripts;
        $post = null;
        $wp_query = null;
        $wp_scripts = null;
    }

    private function newEnqueuer(): AlpineJsEnqueuer
    {
        return new AlpineJsEnqueuer(new AlpineDeferAttribute());
    }

    public function test_register_hooks_wp_footer_priority_5(): void
    {
        $this->newEnqueuer()->register();

        $hook = S::$actions[0]['hook'] ?? null;
        $priority = S::$actions[0]['priority'] ?? null;
        $this->assertSame('wp_footer', $hook);
        $this->assertSame(5, $priority);
    }

    public function test_skips_enqueue_when_no_shortcode_or_block_on_page(): void
    {
        $this->newEnqueuer()->maybeEnqueue();

        $this->assertSame([], S::$enqueuedScripts);
    }

    public function test_enqueues_alpine_when_post_has_shortcode(): void
    {
        global $post;
        $post = (object) ['post_content' => '[dataflair_toplist id=1]'];
        S::$hasShortcode[$post->post_content] = ['dataflair_toplist' => true];

        $this->newEnqueuer()->maybeEnqueue();

        $this->assertCount(1, S::$enqueuedScripts);
        $this->assertSame('alpinejs', S::$enqueuedScripts[0]['handle']);
        $this->assertSame('3.13.5', S::$enqueuedScripts[0]['version']);
        $this->assertTrue(S::$enqueuedScripts[0]['in_footer']);
        $this->assertStringContainsString(
            'cdn.jsdelivr.net/npm/alpinejs',
            S::$enqueuedScripts[0]['src']
        );
    }

    public function test_widget_shortcode_flag_triggers_enqueue(): void
    {
        WidgetShortcodeDetector::$shortcodeUsed = true;

        $this->newEnqueuer()->maybeEnqueue();

        $this->assertCount(1, S::$enqueuedScripts);
    }

    public function test_skips_enqueue_when_alpine_already_registered(): void
    {
        global $post;
        $post = (object) ['post_content' => '[dataflair_toplist]'];
        S::$hasShortcode[$post->post_content] = ['dataflair_toplist' => true];
        S::$scriptIs['alpinejs'] = ['enqueued' => false, 'registered' => true];

        $this->newEnqueuer()->maybeEnqueue();

        $this->assertSame([], S::$enqueuedScripts);
    }

    public function test_alpine_url_filter_is_respected(): void
    {
        global $post;
        $post = (object) ['post_content' => '[dataflair_toplist]'];
        S::$hasShortcode[$post->post_content] = ['dataflair_toplist' => true];
        S::$filterMap['dataflair_alpinejs_url'] = 'https://my-cdn.test/alpine.js';

        $this->newEnqueuer()->maybeEnqueue();

        $this->assertSame('https://my-cdn.test/alpine.js', S::$enqueuedScripts[0]['src']);
    }

    public function test_static_guard_runs_detection_at_most_once(): void
    {
        global $post;
        $post = (object) ['post_content' => '[dataflair_toplist]'];
        S::$hasShortcode[$post->post_content] = ['dataflair_toplist' => true];

        $enqueuer = $this->newEnqueuer();
        $enqueuer->maybeEnqueue();
        $enqueuer->maybeEnqueue();

        $this->assertCount(1, S::$enqueuedScripts);
    }

    /**
     * Regression test: reproduced live on a Roots/Acorn (Sage-based) theme,
     * which registers its compiled assets with `$script->src` as a
     * `Roots\Acorn\Assets\Asset\Asset` value object instead of a plain
     * string. strpos() on a non-string, non-coerced argument throws a
     * TypeError, which the theme's Blade layer turns into a fatal
     * ViewException on every single front-end page load.
     */
    public function test_does_not_crash_when_a_registered_scripts_src_is_a_non_string_object(): void
    {
        global $post, $wp_scripts;
        $post = (object) ['post_content' => '[dataflair_toplist]'];
        S::$hasShortcode[$post->post_content] = ['dataflair_toplist' => true];

        $wp_scripts = (object) [
            'queue' => ['theme-app'],
            'registered' => [
                'theme-app' => (object) [
                    'src' => new class {
                        public function __toString(): string
                        {
                            return '/app/themes/sigma-main/public/build/assets/app.js';
                        }
                    },
                    'deps' => [],
                ],
            ],
        ];

        $this->newEnqueuer()->maybeEnqueue();

        // Not Alpine, so detection should correctly find nothing and still
        // enqueue our own copy — not fatal, and not a false "already found".
        $this->assertCount(1, S::$enqueuedScripts);
        $this->assertSame('alpinejs', S::$enqueuedScripts[0]['handle']);
    }

    /** Same object-wrapped-src shape, but this time it really is Alpine. */
    public function test_detects_alpine_when_registered_scripts_src_is_a_stringable_object(): void
    {
        global $post, $wp_scripts;
        $post = (object) ['post_content' => '[dataflair_toplist]'];
        S::$hasShortcode[$post->post_content] = ['dataflair_toplist' => true];

        $wp_scripts = (object) [
            'queue' => ['theme-alpine'],
            'registered' => [
                'theme-alpine' => (object) [
                    'src' => new class {
                        public function __toString(): string
                        {
                            return 'https://cdn.example.test/alpine.min.js';
                        }
                    },
                    'deps' => [],
                ],
            ],
        ];

        $this->newEnqueuer()->maybeEnqueue();

        $this->assertSame([], S::$enqueuedScripts);
    }
}
