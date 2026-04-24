<?php
/**
 * Thin admin-page delegator for the DataFlair main settings screen.
 *
 * Phase 5 strangler-fig: the page class is the new public seam; the 700+
 * lines of legacy HTML still live in DataFlair_Toplists::settings_page() and
 * render through the provided closure. A follow-up release will move the
 * HTML body into `views/admin/settings.php` without changing this surface.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Pages;

final class SettingsPage implements PageInterface
{
    public function __construct(private \Closure $legacyRenderer) {}

    public function render(): void
    {
        ($this->legacyRenderer)();
    }
}
