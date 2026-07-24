<?php

declare(strict_types=1);

namespace DataFlair\Toplists\Geo;

/**
 * Resolves the current visitor's country as an uppercase ISO 3166-1 alpha-2
 * code, or null when it cannot be determined.
 *
 * Read-only by contract: implementations must not make HTTP calls or writes.
 * RenderIsReadOnlyTest enforces this holds for every render-path caller.
 */
interface VisitorGeoResolverInterface
{
    public function resolve(): ?string;
}
