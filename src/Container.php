<?php
/**
 * Phase 8 — Lightweight lazy service container.
 *
 * Hand-written by design: zero external deps (no Symfony, no Pimple, no PSR
 * Container). Stores factories keyed by string; resolves lazily; memoises
 * the first resolution. That is the entire surface.
 *
 * Keeping the container tiny makes it easy for downstream integrators to
 * subclass `Plugin` and replace individual factories for tests, or to
 * short-circuit any service by calling `Container::set('name', new Fake())`.
 */

declare(strict_types=1);

namespace DataFlair\Toplists;

final class Container
{
    /** @var array<string, \Closure> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $resolved = [];

    /**
     * Register a factory. The closure receives this container so factories
     * can pull their own dependencies.
     *
     * @param \Closure(Container): mixed $factory
     */
    public function register(string $id, \Closure $factory): void
    {
        $this->factories[$id] = $factory;
        // If a previous resolution is cached, invalidate it so the next
        // `get()` picks up the new factory — useful in test harnesses.
        unset($this->resolved[$id]);
    }

    /**
     * Replace a resolved instance outright. Primarily for tests and
     * downstream overrides.
     */
    public function set(string $id, mixed $instance): void
    {
        $this->resolved[$id] = $instance;
    }

    /**
     * Resolve a service. Throws RuntimeException if neither a cached
     * instance nor a factory exists.
     */
    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->resolved)) {
            return $this->resolved[$id];
        }
        if (!array_key_exists($id, $this->factories)) {
            throw new \RuntimeException(sprintf('DataFlair container: no factory registered for "%s".', $id));
        }
        $this->resolved[$id] = ($this->factories[$id])($this);
        return $this->resolved[$id];
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->resolved) || array_key_exists($id, $this->factories);
    }
}
