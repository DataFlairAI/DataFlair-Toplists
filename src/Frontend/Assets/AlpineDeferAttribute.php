<?php
/**
 * Phase 9.8 — Alpine.js script tag defer attribute.
 *
 * `script_loader_tag` filter; rewrites the `<script src="…alpine…">` tag
 * to include a `defer` attribute so Alpine doesn't block render. Hooked
 * conditionally by `AlpineJsEnqueuer` only when this plugin enqueued
 * Alpine itself (not when the theme/another plugin already did).
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Frontend\Assets;

final class AlpineDeferAttribute
{
    /**
     * @param string $tag
     * @param string $handle
     */
    public function filter($tag, $handle)
    {
        if ('alpinejs' === $handle && is_string($tag)) {
            return str_replace(' src', ' defer src', $tag);
        }
        return $tag;
    }
}
