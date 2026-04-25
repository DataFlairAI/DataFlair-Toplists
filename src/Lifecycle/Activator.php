<?php
/**
 * Phase 9.5 — WPPB-style activation handler.
 *
 * Owns what runs when the plugin is activated from wp-admin/plugins.php.
 * Previously lived as `DataFlair_Toplists::activate()` (an instance
 * method registered via `register_activation_hook` inside `init_hooks`).
 *
 * WordPress requires activation hooks to be registered at plugin-file
 * load time with `register_activation_hook(__FILE__, …)`. The plugin
 * file now calls `Activator::activate` directly; the god-class keeps its
 * `activate()` method as a thin delegator for any downstream code that
 * may still call it explicitly.
 *
 * Single responsibility: run the activation-time DDL. Delegates the
 * actual table creation to {@see \DataFlair\Toplists\Database\SchemaMigrator}
 * so the same SQL is never duplicated between activation and
 * self-healing paths.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Lifecycle;

use DataFlair\Toplists\Database\SchemaMigrator;

final class Activator
{
    /**
     * Create (or upgrade) the plugin's custom tables.
     *
     * Must stay idempotent — WordPress may fire activation multiple
     * times (reactivate, multisite network-activate, WP-CLI
     * `plugin activate`). `dbDelta()` handles idempotence internally.
     */
    public static function activate(): void
    {
        (new SchemaMigrator())->createTables();
    }
}
