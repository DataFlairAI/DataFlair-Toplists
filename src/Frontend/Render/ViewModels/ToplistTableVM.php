<?php
declare(strict_types=1);

namespace DataFlair\Toplists\Frontend\Render\ViewModels;

/**
 * Immutable view-model for the toplist-table (accordion) template.
 *
 * Used by the block-editor's debug/testing-focused accordion layout. The
 * production frontend renders casino cards via CasinoCardVM + CardRenderer.
 */
final class ToplistTableVM
{
    /**
     * @param array<int,array<string,mixed>>     $items         Toplist items array from the payload.
     * @param string                             $title         Optional title rendered above the accordion.
     * @param bool                               $isStale       Whether to display the stale-data notice.
     * @param int                                $lastSynced    Unix timestamp of the last successful sync.
     * @param array<string,array<string,mixed>>  $prosConsData  Per-card pros/cons overrides keyed by casino key.
     */
    public function __construct(
        public readonly array $items,
        public readonly string $title,
        public readonly bool $isStale,
        public readonly int $lastSynced,
        public readonly array $prosConsData = [],
    ) {}
}
