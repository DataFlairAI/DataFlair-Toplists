<?php
/**
 * No-op logger. Discards every call.
 *
 * Useful as a default when a site owner wants to silence the plugin's
 * error_log output (e.g. very-high-traffic site with its own log
 * aggregation pipeline).
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Logging;

final class NullLogger implements LoggerInterface
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
