<?php
/**
 * Seam around the curl_* extension calls PersistentCurlTransport makes.
 *
 * curl is a compiled C extension, not a userland function — Brain\Monkey /
 * Patchwork cannot intercept it (confirmed: Functions\when('curl_init') is
 * silently ignored and the real extension function runs anyway, unlike
 * ext-standard functions such as sleep()). This interface exists purely so
 * tests can substitute a fake driver; production always resolves to
 * RealCurlDriver via PersistentCurlTransport::driver().
 *
 * @package DataFlair\Toplists\Http
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Http;

interface CurlDriverInterface
{
    /**
     * A ready-to-configure handle: freshly initialised, or reset for reuse.
     *
     * @return mixed
     */
    public function handle();

    /**
     * @param mixed $handle
     */
    public function setOptArray($handle, array $options): void;

    /**
     * @param mixed $handle
     * @return mixed
     */
    public function exec($handle);

    /**
     * @param mixed $handle
     * @return mixed
     */
    public function getInfo($handle, int $option);

    /**
     * @param mixed $handle
     */
    public function errno($handle): int;

    /**
     * @param mixed $handle
     */
    public function error($handle): string;
}
