<?php
/**
 * API Contract Safety P2 — persistent contract-mismatch admin warning.
 *
 * Renders a top-of-screen admin notice for every sync stream currently
 * paused on a contract mismatch. Entries clear on the next fully successful
 * sync of the same stream, so the notice disappears on its own once the
 * contract works again.
 *
 * Single responsibility: surface the mismatch. Detection and persistence
 * live in DataFlair\Toplists\Sync\ContractMismatch.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Notices;

use DataFlair\Toplists\Sync\ContractMismatch;

final class ContractMismatchNotice
{
    public function register(): void
    {
        add_action('admin_notices', [$this, 'maybeRender']);
    }

    public function maybeRender(): void
    {
        // Only admins can act on the message (update the plugin / run a
        // sync), and the capability check is free while the option lookup
        // costs a query on healthy sites.
        if (!current_user_can('manage_options')) {
            return;
        }

        foreach (ContractMismatch::entries() as $entry) {
            $min = isset($entry['min_plugin_version']) && is_string($entry['min_plugin_version'])
                ? $entry['min_plugin_version']
                : '';

            echo '<div class="notice notice-error"><p>'
               . '<strong>DataFlair sync is paused:</strong> '
               . esc_html((string) $entry['message'])
               . ' Your site continues to show the last synced data.';

            if ($min !== '') {
                echo ' Please update the DataFlair Toplists plugin to version '
                   . esc_html($min)
                   . ' or newer on the <a href="' . esc_url(admin_url('plugins.php')) . '">Plugins</a> page.';
            } else {
                // Never leave the admin without a next step: everything that
                // reaches this branch is a DataFlair-side change they cannot
                // fix from WordPress.
                echo ' ' . esc_html(ContractMismatch::whatToDo(''));
            }

            echo '</p></div>';
        }
    }
}
