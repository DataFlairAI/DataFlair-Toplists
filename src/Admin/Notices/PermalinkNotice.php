<?php
/**
 * Phase 9.6 — Plain-permalinks admin warning.
 *
 * Renders a top-of-screen admin notice when WordPress is configured for
 * "Plain" permalinks. The Gutenberg block + REST endpoints can't function
 * under plain permalinks, so this is a hard prerequisite for using the
 * plugin's editor surface. Extracted from
 * `DataFlair_Toplists::maybe_notice_plain_permalinks()`.
 *
 * Single responsibility: surface the permalink misconfig. Nothing else.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Notices;

final class PermalinkNotice
{
    public function register(): void
    {
        add_action('admin_notices', [$this, 'maybeRender']);
    }

    public function maybeRender(): void
    {
        if (!empty(get_option('permalink_structure'))) {
            return;
        }

        echo '<div class="notice notice-error"><p>'
           . '<strong>DataFlair:</strong> The Gutenberg block requires '
           . 'pretty permalinks. Go to <a href="' . admin_url('options-permalink.php') . '">'
           . 'Settings &rarr; Permalinks</a> and choose any option other than Plain.'
           . '</p></div>';
    }
}
