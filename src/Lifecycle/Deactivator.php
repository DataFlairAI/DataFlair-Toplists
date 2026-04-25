<?php
/**
 * Phase 9.5 — WPPB-style deactivation handler.
 *
 * Previously lived as `DataFlair_Toplists::deactivate()`. Cron hooks
 * were removed in v1.11.0 (Phase 0B H1), but this handler still clears
 * the legacy hook names so re-activation on a site that was upgraded
 * from v1.10.x and then deactivated can't leave orphan schedules.
 *
 * Single responsibility: undo anything activation set up that should
 * not survive across a deactivate/reactivate cycle.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Lifecycle;

final class Deactivator
{
    public static function deactivate(): void
    {
        if (!function_exists('wp_clear_scheduled_hook')) {
            return;
        }
        wp_clear_scheduled_hook('dataflair_sync_cron');
        wp_clear_scheduled_hook('dataflair_brands_sync_cron');
    }
}
