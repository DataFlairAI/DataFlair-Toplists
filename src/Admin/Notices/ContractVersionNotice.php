<?php
/**
 * API Contract Safety — "the DataFlair API moved" informational notice.
 *
 * Answers the tenant question "how do we find out when you change the API?".
 * This is deliberately an INFO notice, not an error: everything it reports is
 * safe by policy (revisions are additive within a version, and a newer API
 * version never affects a site until it deliberately updates the plugin).
 *
 * It is dismissible, and dismissing records the current reading as seen, so
 * the next genuine change surfaces again.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Notices;

use DataFlair\Toplists\Sync\ContractVersion;

final class ContractVersionNotice
{
    private const ACK_PARAM = 'dataflair_ack_contract';
    private const NONCE     = 'dataflair_ack_contract';

    public function register(): void
    {
        add_action('admin_init', [$this, 'maybeAcknowledge']);
        add_action('admin_notices', [$this, 'maybeRender']);
    }

    /** Handle the dismiss link. */
    public function maybeAcknowledge(): void
    {
        if (empty($_GET[self::ACK_PARAM]) || !current_user_can('manage_options')) {
            return;
        }

        // is_scalar first: `?_wpnonce[]=x` would otherwise raise an
        // Array-to-string warning on every admin screen, including
        // admin-ajax.php, which fires admin_init too.
        $nonce = $_GET['_wpnonce'] ?? null;
        if (!is_scalar($nonce) || !wp_verify_nonce((string) $nonce, self::NONCE)) {
            return;
        }

        ContractVersion::acknowledge();
    }

    public function maybeRender(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $pending = ContractVersion::pending();
        if ($pending === null) {
            return;
        }

        $parts = [];

        if ($pending['previous'] !== '' && $pending['rev'] !== $pending['previous']) {
            $parts[] = 'The DataFlair API contract moved from ' . esc_html($pending['previous'])
                . ' to ' . esc_html($pending['rev'])
                . '. Changes within a version are additive, so your site keeps working and there is nothing to do.';
        }

        if ($pending['newer_versions'] !== []) {
            $parts[] = 'A newer API version is now available ('
                . esc_html(implode(', ', $pending['newer_versions']))
                . '). This plugin stays on ' . esc_html($pending['using'])
                . ' until you install a plugin version that uses the newer one, so nothing changes for you today.';
        }

        if ($parts === []) {
            return;
        }

        // Pinned to a known admin URL rather than add_query_arg()'s default
        // of the raw REQUEST_URI, so the link this notice invites an admin to
        // click can never carry an attacker-chosen path or query.
        $dismiss = wp_nonce_url(
            add_query_arg(self::ACK_PARAM, '1', admin_url('admin.php?page=dataflair')),
            self::NONCE
        );

        echo '<div class="notice notice-info"><p>'
           . '<strong>DataFlair API update:</strong> '
           . implode(' ', $parts)
           . ' <a href="' . esc_url($dismiss) . '">Dismiss</a>'
           . '</p></div>';
    }
}
