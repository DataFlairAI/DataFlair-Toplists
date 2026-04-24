<?php
declare(strict_types=1);

namespace DataFlair\Toplists\Frontend\Render;

use DataFlair\Toplists\Frontend\Render\ViewModels\ToplistTableVM;

/**
 * Public contract for rendering the block-editor accordion-table layout.
 *
 * Swap the default via the `dataflair_table_renderer` filter. Non-interface
 * filter returns are rejected and the default is kept.
 */
interface TableRendererInterface
{
    /**
     * Render the accordion-table layout for the block testing UI.
     *
     * @return string Rendered HTML.
     */
    public function render(ToplistTableVM $vm): string;
}
