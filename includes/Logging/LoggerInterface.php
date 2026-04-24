<?php
/**
 * Phase 1 — Observability Foundation.
 *
 * A deliberately tiny logging contract. PSR-3-shaped so it reads like what
 * developers expect, but not literally PSR-3 — we do NOT depend on
 * psr/log. The plugin's autoloader is committed vendor-only; pulling a
 * real PSR-3 dep would bloat every client install with code that only
 * value lies in swappability.
 *
 * Consumers register an implementation via the `dataflair_logger` filter:
 *
 *   add_filter('dataflair_logger', fn() => new SentryAdapter());
 *
 * The factory caches the resolved instance so the filter runs once per
 * request, not per call site.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Logging;

interface LoggerInterface
{
    public function emergency(string $message, array $context = []): void;

    public function alert(string $message, array $context = []): void;

    public function critical(string $message, array $context = []): void;

    public function error(string $message, array $context = []): void;

    public function warning(string $message, array $context = []): void;

    public function notice(string $message, array $context = []): void;

    public function info(string $message, array $context = []): void;

    public function debug(string $message, array $context = []): void;
}
