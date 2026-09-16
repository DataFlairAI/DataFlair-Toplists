<?php
/**
 * Phase 9.8 — Conditional Alpine.js enqueue.
 *
 * Hooks `wp_footer` (priority 5) and decides whether the page actually
 * needs Alpine. Logic preserved byte-for-byte from
 * `DataFlair_Toplists::maybe_enqueue_alpine()`:
 *   1. Has the current post or any queried post used the shortcode/block?
 *   2. Did a widget on this page use the shortcode?
 *   3. Has any other script registered Alpine under a known handle?
 *   4. Is any registered/enqueued script's src or deps Alpine-shaped?
 *
 * Only when steps 1–2 say yes AND steps 3–4 say no do we enqueue the CDN
 * build and attach the defer-attribute filter.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Frontend\Assets;

final class AlpineJsEnqueuer
{
    /** Per-request guard so the heavy detection runs at most once. */
    private static bool $checked = false;

    public function __construct(
        private readonly AlpineDeferAttribute $deferFilter
    ) {
    }

    public function register(): void
    {
        add_action('wp_footer', [$this, 'maybeEnqueue'], 5);
    }

    public function maybeEnqueue(): void
    {
        if (self::$checked) {
            return;
        }
        self::$checked = true;

        if (!$this->pageUsesShortcodeOrBlock()) {
            return;
        }

        if ($this->alpineAlreadyEnqueued()) {
            return;
        }

        $alpineUrl = apply_filters(
            'dataflair_alpinejs_url',
            'https://cdn.jsdelivr.net/npm/alpinejs@3.13.5/dist/cdn.min.js'
        );

        wp_enqueue_script(
            'alpinejs',
            $alpineUrl,
            [],
            '3.13.5',
            true
        );

        add_filter('script_loader_tag', [$this->deferFilter, 'filter'], 10, 2);
    }

    private function pageUsesShortcodeOrBlock(): bool
    {
        global $post;

        $hasShortcode = false;
        $hasBlock     = false;

        if ($post) {
            $hasShortcode = has_shortcode($post->post_content, 'dataflair_toplist');
            $hasBlock     = has_block('dataflair-toplists/toplist', $post);
        }

        if (WidgetShortcodeDetector::$shortcodeUsed) {
            $hasShortcode = true;
        }

        if (!$hasShortcode && !$hasBlock) {
            global $wp_query;
            if ($wp_query && !empty($wp_query->posts)) {
                foreach ($wp_query->posts as $queryPost) {
                    if (
                        has_shortcode($queryPost->post_content, 'dataflair_toplist')
                        || has_block('dataflair-toplists/toplist', $queryPost)
                    ) {
                        return true;
                    }
                }
            }
        }

        return $hasShortcode || $hasBlock;
    }

    private function alpineAlreadyEnqueued(): bool
    {
        $alpineHandles = ['alpinejs', 'alpine', 'alpine-js', 'alpine.js'];

        foreach ($alpineHandles as $handle) {
            if (wp_script_is($handle, 'enqueued') || wp_script_is($handle, 'registered')) {
                return true;
            }
        }

        global $wp_scripts;

        if ($wp_scripts && !empty($wp_scripts->queue)) {
            foreach ($wp_scripts->queue as $queuedHandle) {
                $script = $wp_scripts->registered[$queuedHandle] ?? null;
                $src    = $script->src ?? null;

                // Some themes (e.g. Roots/Acorn-based Sage themes) register
                // ->src as an asset value object rather than a plain string.
                // strpos() requires a real string, so coerce via __toString()
                // when available and skip otherwise instead of fataling.
                if (is_object($src)) {
                    $src = method_exists($src, '__toString') ? (string) $src : null;
                }

                if (
                    $script
                    && is_string($src)
                    && (
                        strpos($src, 'alpine') !== false
                        || strpos($src, 'alpinejs') !== false
                    )
                ) {
                    return true;
                }
            }
        }

        if (isset($wp_scripts) && $wp_scripts) {
            foreach ($wp_scripts->registered as $script) {
                if (isset($script->deps) && is_array($script->deps)) {
                    foreach ($script->deps as $dep) {
                        if (in_array($dep, $alpineHandles, true)) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /** Test seam — reset the static guard between assertions. */
    public static function resetForTests(): void
    {
        self::$checked = false;
    }
}
