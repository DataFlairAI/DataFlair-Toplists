<?php
/**
 * Default logger implementation. Writes to PHP's error_log() with a
 * [DataFlair][LEVEL] prefix, so operators can grep a single site log.
 *
 * Filters by minimum level to keep production logs quiet by default
 * (`notice` and above). Flip to `debug` via the `dataflair_logger_level`
 * filter when diagnosing.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Logging;

final class ErrorLogLogger implements LoggerInterface
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

    private int $minLevel;

    public function __construct(string $minLevel = 'notice')
    {
        $resolved = function_exists('apply_filters')
            ? (string) apply_filters('dataflair_logger_level', $minLevel)
            : $minLevel;
        $this->minLevel = self::LEVELS[$resolved] ?? self::LEVELS['notice'];
    }

    public function emergency(string $message, array $context = []): void { $this->write('emergency', $message, $context); }
    public function alert(string $message, array $context = []): void     { $this->write('alert',     $message, $context); }
    public function critical(string $message, array $context = []): void  { $this->write('critical',  $message, $context); }
    public function error(string $message, array $context = []): void     { $this->write('error',     $message, $context); }
    public function warning(string $message, array $context = []): void   { $this->write('warning',   $message, $context); }
    public function notice(string $message, array $context = []): void    { $this->write('notice',    $message, $context); }
    public function info(string $message, array $context = []): void      { $this->write('info',      $message, $context); }
    public function debug(string $message, array $context = []): void     { $this->write('debug',     $message, $context); }

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

        error_log(sprintf('[DataFlair][%s] %s%s', strtoupper($level), $message, $payload));
    }
}
