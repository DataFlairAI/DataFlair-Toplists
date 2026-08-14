<?php
/**
 * Default CurlDriverInterface, backed by the real curl extension.
 *
 * Owns the reused CurlHandle (curl_reset instead of curl_init on every call
 * after the first) so the TLS connection to repeated upstream hosts stays
 * warm — this is the behaviour PersistentCurlTransport's class docblock
 * documents; the handle itself now lives here instead of as a static
 * property on PersistentCurlTransport so a test double never has to satisfy
 * the `?\CurlHandle` type.
 *
 * @package DataFlair\Toplists\Http
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Http;

final class RealCurlDriver implements CurlDriverInterface
{
    private ?\CurlHandle $handle = null;

    public function handle()
    {
        if ($this->handle === null) {
            $this->handle = curl_init();
        } else {
            curl_reset($this->handle);
        }
        return $this->handle;
    }

    public function setOptArray($handle, array $options): void
    {
        curl_setopt_array($handle, $options);
    }

    public function exec($handle)
    {
        return curl_exec($handle);
    }

    public function getInfo($handle, int $option)
    {
        return curl_getinfo($handle, $option);
    }

    public function errno($handle): int
    {
        return curl_errno($handle);
    }

    public function error($handle): string
    {
        return curl_error($handle);
    }
}
