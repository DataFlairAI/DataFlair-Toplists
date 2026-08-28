<?php
/**
 * API Contract Safety P2 — persistent contract-mismatch admin warning.
 *
 * Renders a top-of-screen admin notice while the ContractMismatch option is
 * set (sync hit a 409 contract_mismatch rejection from the backend). The
 * option is cleared by the next fully successful sync of the same stream,
 * so the notice disappears on its own once the contract works again.
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
        $state = get_option(ContractMismatch::OPTION);
        if (!is_array($state) || empty($state['message'])) {
            return;
        }

        $min = (string) ($state['min_plugin_version'] ?? '');

        echo '<div class="notice notice-error"><p>'
           . '<strong>DataFlair sync is paused:</strong> '
           . esc_html((string) $state['message'])
           . ' Your site continues to show the last synced data.';

        if ($min !== '') {
            echo ' Please update the DataFlair Toplists plugin to version '
               . esc_html($min)
               . ' or newer on the <a href="' . admin_url('plugins.php') . '">Plugins</a> page.';
        }

        echo '</p></div>';
    }
}
