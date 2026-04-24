<?php
/**
 * Backwards-compatible forwarding shim.
 *
 * Phase 4 moved the casino-card template to views/frontend/casino-card.php.
 * Existing downstream code that still references
 * `includes/render-casino-card.php` keeps working for one release cycle
 * (v1.13.0); the shim is removed in Phase 5 (v1.14.0).
 */

if (!defined('ABSPATH')) {
    exit;
}

require __DIR__ . '/../views/frontend/casino-card.php';
