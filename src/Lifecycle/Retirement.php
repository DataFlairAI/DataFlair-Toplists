<?php
declare(strict_types=1);
namespace DataFlair\Toplists\Lifecycle;

/** Irreversible retirement migration. Runs when the new code is first loaded. */
final class Retirement
{
    public const OPTION = 'dataflair_retired_245';
    public const TABLES = ['dataflair_toplists', 'dataflair_brands', 'dataflair_alternative_toplists', 'dataflair_webhook_events'];

    public static function boot(string $pluginFile): void
    {
        self::purge();
        register_activation_hook($pluginFile, [self::class, 'purge']);
        (new \DataFlair\Toplists\Frontend\Redirect\CampaignRedirectHandler())->register();
        (new \DataFlair\Toplists\UpdateChecker\GithubUpdateChecker($pluginFile))->register();
        (new \DataFlair\Toplists\Admin\PluginInfoFilter())->register();
        add_shortcode('dataflair_toplist', static fn() => '');
        add_filter('render_block_data', static function (array $block): array {
            if (($block['blockName'] ?? '') === 'dataflair-toplists/toplist') {
                $block['innerHTML'] = '';
                $block['innerContent'] = [];
                $block['innerBlocks'] = [];
            }
            return $block;
        });
    }

    public static function purge(): void
    {
        if (get_option(self::OPTION) === 'complete') {
            return;
        }
        global $wpdb;
        $ok = true;
        foreach (self::TABLES as $suffix) {
            $table = str_replace('`', '``', $wpdb->prefix . $suffix);
            if ($wpdb->query("DROP TABLE IF EXISTS `{$table}`") === false) {
                $ok = false;
            }
        }
        // Leave core tables, API settings, posts/media, and tracker transients alone.
        // The disabled redirect never reads those transients.
        wp_clear_scheduled_hook('dataflair_sync_cron');
        wp_clear_scheduled_hook('dataflair_brands_sync_cron');
        if ($ok) {
            update_option(self::OPTION, 'complete', false);
        } else {
            add_action('admin_notices', static function (): void {
                echo '<div class="notice notice-error"><p>DataFlair retirement: table deletion failed and will retry. Redirects and sync remain disabled.</p></div>';
            });
        }
    }
}
