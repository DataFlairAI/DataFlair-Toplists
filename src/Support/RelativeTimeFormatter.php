<?php
/**
 * Phase 9.11 — Relative time helpers.
 *
 * Stateless string formatting for "3 minutes ago" / "in 3 minutes"
 * style labels. Uses `time()` directly so callers do not need to pass
 * a clock; if a future feature wants deterministic output the methods
 * can be re-shaped to take a `?int $now` parameter.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Support;

final class RelativeTimeFormatter
{
    public function timeAgo(int $timestamp): string
    {
        $diff = time() - $timestamp;

        if ($diff < 10) {
            return 'just now';
        }
        if ($diff < 60) {
            return $diff . ' seconds ago';
        }
        if ($diff < 120) {
            return '1 minute ago';
        }
        if ($diff < 3600) {
            return floor($diff / 60) . ' minutes ago';
        }
        if ($diff < 7200) {
            return '1 hour ago';
        }
        if ($diff < 86400) {
            return floor($diff / 3600) . ' hours ago';
        }
        return date('Y-m-d H:i', $timestamp);
    }

    public function timeUntil(int $timestamp): string
    {
        $diff = $timestamp - time();

        if ($diff <= 0) {
            return 'any moment';
        }
        if ($diff < 60) {
            return 'in ' . $diff . ' seconds';
        }
        if ($diff < 120) {
            return 'in 1 minute';
        }
        if ($diff < 3600) {
            return 'in ' . floor($diff / 60) . ' minutes';
        }
        if ($diff < 7200) {
            return 'in 1 hour';
        }
        return 'in ' . floor($diff / 3600) . ' hours';
    }
}
