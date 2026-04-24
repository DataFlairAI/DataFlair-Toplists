<?php
/**
 * Shared contract for every DataFlair admin page class. Each page exposes a
 * single `render()` entry-point that `add_menu_page` / `add_submenu_page`
 * callbacks route through (via a closure bound to the instance).
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Pages;

interface PageInterface
{
    public function render(): void;
}
