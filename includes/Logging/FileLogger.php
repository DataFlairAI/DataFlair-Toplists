<?php
/**
 * File-based logger. Writes structured, timestamped lines to a dedicated
 * sync log file (default: wp-content/dataflair-sync.log). Useful for
 * tailing what the sync is doing in real time without polluting the
 * shared WP debug.log.
 *
 * Filterable path via `dataflair_sync_log_path`.
 * Rotates at 5 MB by renaming to .1 (single-generation, no compression).
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Logging;

final class FileLogger implements LoggerInterface
{
    private const LEVELS = [
        'debug'     => 0,
        'info'      => 1,
        'notice'    => 2,
        'warning'   => 3,
        'error'     => 4,
        'critical'  => 5,
        'alert'     => 6,
        'emergency' => 7,
    ];

    private const MAX_BYTES = 5 * 1024 * 1024;

    private int $minLevel;
    private string $path;

    public function __construct(string $minLevel = 'debug', ?string $path = null)
    {
        $resolved = function_exists('apply_filters')
            ? (string) apply_filters('dataflair_logger_level', $minLevel)
            : $minLevel;
        $this->minLevel = self::LEVELS[$resolved] ?? self::LEVELS['debug'];

        $default = (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : __DIR__) . '/dataflair-sync.log';
        $this->path = $path ?? (function_exists('apply_filters')
            ? (string) apply_filters('dataflair_sync_log_path', $default)
            : $default);
    }

    public function emergency(string $message, array $context = []): void { $this->write('emergency', $message, $context); }
    public function alert(string $message, array $context = []): void     { $this->write('alert',     $message, $context); }
    public function critical(string $message, array $context = []): void  { $this->write('critical',  $message, $context); }
    public function error(string $message, array $context = []): void     { $this->write('error',     $message, $context); }
    public function warning(string $message, array $context = []): void   { $this->write('warning',   $message, $context); }
    public function notice(string $message, array $context = []): void    { $this->write('notice',    $message, $context); }
    public function info(string $message, array $context = []): void      { $this->write('info',      $message, $context); }
    public function debug(string $message, array $context = []): void     { $this->write('debug',     $message, $context); }

    public function path(): string
    {
        return $this->path;
    }

    private function write(string $level, string $message, array $context): void
    {
        if ((self::LEVELS[$level] ?? 0) < $this->minLevel) {
            return;
        }

        $payload = '';
        if ($context !== []) {
            $encoded = function_exists('wp_json_encode')
                ? wp_json_encode($context)
                : json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if (is_string($encoded)) {
                $payload = ' ' . $encoded;
            }
        }

        $ts   = gmdate('Y-m-d H:i:s');
        $line = sprintf("[%s UTC][%s] %s%s\n", $ts, strtoupper($level), $message, $payload);

        $this->maybeRotate();
        @file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);
    }

    private function maybeRotate(): void
    {
        if (!is_file($this->path)) {
            return;
        }
        $size = @filesize($this->path);
        if ($size === false || $size < self::MAX_BYTES) {
            return;
        }
        $rotated = $this->path . '.1';
        if (is_file($rotated)) {
            @unlink($rotated);
        }
        @rename($this->path, $rotated);
    }
}
