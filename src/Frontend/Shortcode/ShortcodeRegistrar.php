<?php
/**
 * Phase 9.12 — owns the `[dataflair_toplist]` shortcode registration.
 *
 * Takes the shortcode callback as a generic `callable` so registration
 * can defer the heavy-weight orchestrator construction. The legacy
 * delegator (`[$plugin, 'toplist_shortcode']`) is the canonical wiring:
 * it invokes the lazy `toplist_shortcode_instance()` getter only when WP
 * actually processes the shortcode in page content, which is after every
 * other plugin has had a chance to register its
 * `dataflair_card_renderer` / `dataflair_table_renderer` filters.
 *
 * The god-class previously called `add_shortcode(...)` from inside
 * `init_hooks()`. `Plugin::registerHooks()` now wires this registrar
 * instead — `init_hooks()` no longer touches the shortcode surface.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Frontend\Shortcode;

final class ShortcodeRegistrar
{
    /** @var callable */
    private $callback;

    public function __construct(callable $callback)
    {
        $this->callback = $callback;
    }

    public function register(): void
    {
        if (!function_exists('add_shortcode')) {
            return;
        }
        add_shortcode('dataflair_toplist', $this->callback);
    }
}
