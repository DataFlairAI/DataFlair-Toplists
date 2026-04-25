<?php
/**
 * Phase 9.11 — Docker environment detection.
 *
 * Three-way detection: `/.dockerenv` file, `/proc/1/cgroup` keywords,
 * `host.docker.internal` DNS resolution. Used to be wired into Sigma
 * tenant URL substitution; currently unused after Phase 0A but kept
 * as a discrete unit for future tenant-aware bootstrapping.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Support;

final class EnvironmentDetector
{
    /**
     * @return bool True when the PHP process is running inside Docker.
     */
    public function isRunningInDocker(): bool
    {
        if (file_exists('/.dockerenv')) {
            return true;
        }

        if (is_readable('/proc/1/cgroup')) {
            $cgroup = (string) file_get_contents('/proc/1/cgroup');
            if (strpos($cgroup, 'docker') !== false || strpos($cgroup, 'kubepods') !== false) {
                return true;
            }
        }

        $resolved = gethostbyname('host.docker.internal');
        if ($resolved !== 'host.docker.internal') {
            return true;
        }

        return false;
    }
}
