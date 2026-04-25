<?php
/**
 * Phase 9.5 — WPPB-style GitHub update-checker registrar.
 *
 * Encapsulates the YahnisElsts Plugin Update Checker (PUC v5) bootstrap
 * that used to live as a free-floating `if (class_exists(PucFactory))`
 * block at the top of `dataflair-toplists.php`. The plugin file now just
 * instantiates this class and calls `register()`.
 *
 * Single responsibility: wire up auto-updates from GitHub release assets.
 * Do not add feature-detection, changelog formatting, or anything else
 * here — that's what the separate `PluginInfoFilter` owns.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\UpdateChecker;

final class GithubUpdateChecker
{
    public const DEFAULT_REPO = 'https://github.com/DataFlairAI/DataFlair-Toplists/';
    public const DEFAULT_SLUG = 'dataflair-toplists';

    public function __construct(
        private readonly string $pluginFile,
        private readonly string $repoUrl = self::DEFAULT_REPO,
        private readonly string $slug = self::DEFAULT_SLUG
    ) {
    }

    /**
     * Register the update checker. Idempotent — safe to call twice; PUC
     * internally keys checkers by plugin file path so a second call is a
     * no-op.
     *
     * Short-circuits when PUC is not loaded (e.g. tests that don't load
     * the vendor tree, or operator who stripped update-checking out of a
     * private fork).
     */
    public function register(): void
    {
        if (!class_exists('YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory')) {
            return;
        }

        // Guard: PUC's PucFactory::buildUpdateChecker() reads WP_PLUGIN_DIR
        // unconditionally. In unit tests (and other non-WP contexts) that
        // constant is undefined; short-circuit here rather than crash.
        if (!defined('WP_PLUGIN_DIR')) {
            return;
        }

        $checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
            $this->repoUrl,
            $this->pluginFile,
            $this->slug
        );

        // Pull updates from GitHub release assets (zip attachments on the
        // Release page) rather than the auto-generated "Source code" zip.
        // Required because we strip dev deps from the release zip.
        $vcsApi = $checker->getVcsApi();
        if (is_object($vcsApi) && method_exists($vcsApi, 'enableReleaseAssets')) {
            $vcsApi->enableReleaseAssets();
        }
    }
}
