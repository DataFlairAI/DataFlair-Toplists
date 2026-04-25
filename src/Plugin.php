<?php
/**
 * Phase 8 — Plugin bootstrap seam.
 *
 * Replaces the `DataFlair_Toplists::get_instance()` entry point as the
 * canonical bootstrap for the plugin. Through v2.0.x the legacy singleton
 * continues to work (strangler-fig), but the public contract is:
 *
 *     DataFlair\Toplists\Plugin::boot();   // canonical
 *     DataFlair_Toplists::get_instance();  // deprecated, still functional
 *
 * `boot()` is idempotent: repeat calls return the same `Plugin` instance
 * and do not re-register hooks.
 *
 * Services are resolved through {@see Container}; see ::buildContainer()
 * for the wiring. The container is intentionally narrow — only services
 * that need to be shared across hook call sites live there. One-off
 * helpers stay as `new Foo()` inside the legacy code paths until Phase 9.
 */

declare(strict_types=1);

namespace DataFlair\Toplists;

final class Plugin
{
    private static ?self $instance = null;

    private bool $booted = false;

    private Container $container;

    private \DataFlair_Toplists $legacy;

    public static function boot(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        self::$instance->registerHooks();
        return self::$instance;
    }

    public static function instance(): ?self
    {
        return self::$instance;
    }

    /**
     * Reset the static singleton. Test-only seam — do not call from
     * production code. Lets PHPUnit tear down between suites.
     */
    public static function resetForTests(): void
    {
        self::$instance = null;
    }

    private function __construct()
    {
        $this->container = $this->buildContainer();
        // Until the god-class is fully dissolved (Phase 9), the legacy
        // singleton still owns the hook registrations. Plugin::boot() keeps
        // bootstrapping it so all existing behaviour stays intact.
        $this->legacy = \DataFlair_Toplists::get_instance();
    }

    public function container(): Container
    {
        return $this->container;
    }

    /**
     * Idempotent hook registration. The legacy `DataFlair_Toplists::
     * __construct()` already calls `init_hooks()`; this guard prevents a
     * double-register if `boot()` fires more than once in one request.
     */
    private function registerHooks(): void
    {
        if ($this->booted) {
            return;
        }
        // All hooks already registered by DataFlair_Toplists::__construct().
        // Plugin::boot() is currently a provenance marker — it signals that
        // the canonical bootstrap path is the Plugin container, not the
        // singleton. In Phase 9 the hook registrations move here and the
        // legacy class is deleted.
        $this->booted = true;
    }

    private function buildContainer(): Container
    {
        $c = new Container();

        $c->register('logger', static fn() => \DataFlair\Toplists\Logging\LoggerFactory::get());

        return $c;
    }
}
