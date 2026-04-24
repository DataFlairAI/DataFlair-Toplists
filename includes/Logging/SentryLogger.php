<?php
/**
 * Stub Sentry logger.
 *
 * This is deliberately a no-op pass-through. The plugin ships with zero
 * Sentry SDK dependency; installing sentry/sentry-php is the host site's
 * choice. When installed there, a client-side glue class can extend this
 * and wire captureMessage / addBreadcrumb to the real SDK:
 *
 *   class MySentryLogger extends \DataFlair\Toplists\Logging\SentryLogger {
 *       public function error(string $message, array $context = []): void {
 *           \Sentry\captureMessage($message, \Sentry\Severity::error(), $context);
 *       }
 *       // …
 *   }
 *   add_filter('dataflair_logger', fn() => new MySentryLogger());
 *
 * The stub exists so the class name resolves in autoload scanners and
 * plugin checkers; production sites that want real Sentry always
 * subclass or register their own LoggerInterface implementation.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Logging;

class SentryLogger implements LoggerInterface
{
    public function emergency(string $message, array $context = []): void {}
    public function alert(string $message, array $context = []): void {}
    public function critical(string $message, array $context = []): void {}
    public function error(string $message, array $context = []): void {}
    public function warning(string $message, array $context = []): void {}
    public function notice(string $message, array $context = []): void {}
    public function info(string $message, array $context = []): void {}
    public function debug(string $message, array $context = []): void {}
}
