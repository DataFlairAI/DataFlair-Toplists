<?php
/**
 * Adapter that satisfies ToplistPersisterInterface by forwarding to the
 * god-class's existing store_toplist_data() + fetch_and_store_toplist()
 * methods.
 *
 * Phase 3 leaves those methods in place; Phase 4 extracts them into the
 * repository layer. At that point this adapter goes away.
 *
 * @package DataFlair\Toplists\Sync
 * @since   1.12.1 (Phase 3)
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Sync;

final class GodClassToplistPersister implements ToplistPersisterInterface
{
    /** @var \DataFlair_Toplists */
    private $godClass;

    public function __construct(\DataFlair_Toplists $godClass)
    {
        $this->godClass = $godClass;
    }

    public function store(array $toplist, string $rawJson): bool
    {
        // store_toplist_data is private on the god-class; the adapter lives in
        // the same package so a Closure::bind lets us invoke it without making
        // the god-class method public ahead of Phase 4.
        $call = \Closure::bind(
            fn($t, $j) => (bool) $this->store_toplist_data($t, $j),
            $this->godClass,
            \DataFlair_Toplists::class
        );
        return (bool) $call($toplist, $rawJson);
    }

    public function fetchAndStore(string $endpoint, string $token): bool
    {
        $call = \Closure::bind(
            fn($e, $t) => (bool) $this->fetch_and_store_toplist($e, $t),
            $this->godClass,
            \DataFlair_Toplists::class
        );
        return (bool) $call($endpoint, $token);
    }
}
