<?php
/**
 * Phase 9.9 — Last-sync admin label formatter.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Frontend\Render;

/**
 * Build the "Last sync: ..." label for the admin UI.
 *
 * Accepts either the legacy `dataflair_last_*_cron_run` or the new
 * `dataflair_last_*_sync` option name and falls back through the twin.
 * Phase 1 introduced the new option name; both keys remain supported for
 * one release while the brands/settings pages migrate.
 *
 * The relative-time math is duplicated from the god-class `time_ago()` helper
 * for now — Phase 9.11 will extract a dedicated `RelativeTimeFormatter` and
 * this class will inject it.
 */
final class SyncLabelFormatter
{
    public function format(string $option_key): string
    {
        $last_run = get_option($option_key);

        if (!$last_run) {
            $alt = null;
            if (strpos($option_key, '_cron_run') !== false) {
                $alt = str_replace('_cron_run', '_sync', $option_key);
            } elseif (strpos($option_key, '_sync') !== false) {
                $alt = str_replace('_sync', '_cron_run', $option_key);
            }
            if ($alt) {
                $last_run = get_option($alt);
            }
        }

        return 'Last sync: ' . ($last_run ? $this->timeAgo((int) $last_run) : 'never');
    }

    private function timeAgo(int $timestamp): string
    {
        $diff = time() - $timestamp;

        if ($diff < 10) {
            return 'just now';
        }
        if ($diff < 60) {
            return $diff . ' seconds ago';
        }
        if ($diff < 120) {
            return '1 minute ago';
        }
        if ($diff < 3600) {
            return floor($diff / 60) . ' minutes ago';
        }
        if ($diff < 7200) {
            return '1 hour ago';
        }
        if ($diff < 86400) {
            return floor($diff / 3600) . ' hours ago';
        }
        return date('Y-m-d H:i', $timestamp);
    }
}
