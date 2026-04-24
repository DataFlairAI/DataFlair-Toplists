<?php
/**
 * Thin admin-page delegator for the DataFlair brands screen.
 *
 * Phase 5 strangler-fig: the 1,200+ lines of legacy HTML still live in
 * DataFlair_Toplists::brands_page(); this class owns the public seam. A
 * follow-up release will move the HTML body into `views/admin/brands.php`
 * without changing this surface.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Pages;

final class BrandsPage implements PageInterface
{
    public function __construct(private \Closure $legacyRenderer) {}

    public function render(): void
    {
        ($this->legacyRenderer)();
    }
}
