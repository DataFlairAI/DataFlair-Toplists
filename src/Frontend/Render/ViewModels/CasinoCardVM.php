<?php
declare(strict_types=1);

namespace DataFlair\Toplists\Frontend\Render\ViewModels;

/**
 * Immutable view-model for the casino-card template.
 *
 * A thin, well-typed wrapper around the raw arrays the shortcode and block
 * renderer previously passed directly into `render-casino-card.php`. Templates
 * read from a VM instead of a raw array so the payload shape is a public,
 * explicit contract — not whatever the upstream caller happens to provide.
 *
 * Phase 4 keeps the VM minimal: it pins only the fields that cross the
 * renderer / template seam (item, customizations, pros_cons, brand_meta_map).
 * Later phases may project item + brand into narrower typed fields.
 */
final class CasinoCardVM
{
    /**
     * @param array<string,mixed>          $item            Raw toplist item payload (brand, offer, rating, etc.).
     * @param int                          $toplistId       ID of the toplist this card belongs to.
     * @param array<string,mixed>          $customizations  Block-level visual customizations.
     * @param array<string,array<string,mixed>> $prosConsData Per-card pros/cons overrides keyed by casino key.
     * @param array{ids:array<int,object>,slugs:array<string,object>,names:array<string,object>}|null $brandMetaMap
     *   Prefetched brand-row map (Phase 0B H7); null forces legacy per-card cascade.
     */
    public function __construct(
        public readonly array $item,
        public readonly int $toplistId,
        public readonly array $customizations = [],
        public readonly array $prosConsData = [],
        public readonly ?array $brandMetaMap = null,
    ) {}
}
