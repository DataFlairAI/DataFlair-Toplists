<?php
/**
 * Phase 9.6 (admin UX redesign) — Dashboard page (Phase 1 placeholder).
 *
 * Full implementation (stat tiles, recent activity card, scheduled jobs,
 * API health, shortcode usage) arrives in Phase 4. This placeholder keeps
 * the menu wiring intact so all five pages load without PHP errors.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Pages;

final class DashboardPage implements PageInterface
{
    public function render(): void
    {
        ?>
        <div class="wrap">
            <div class="df-page-header">
                <h1 class="df-page-header__title">Dashboard</h1>
            </div>
            <p class="description">
                Full dashboard — stat tiles, recent sync activity, scheduled jobs, and API
                health — is coming in a subsequent release. Use the sidebar to navigate to
                Toplists, Brands, Tools, or Settings.
            </p>
        </div>
        <?php
    }
}
