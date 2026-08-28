<?php
/**
 * `wp dataflair sync` — run a full sync from the command line.
 *
 * The plugin ships no cron by design: nothing may change a site's data
 * without a deliberate trigger. That left the admin button as the only way
 * to sync, while the Settings page already told operators WP-CLI was an
 * option. This command makes that true, and gives sites that want scheduled
 * syncs the correct tool: a real system cron calling WP-CLI, rather than
 * WP-cron firing a multi-minute job on an unlucky visitor's page view.
 *
 *   wp dataflair sync                 # toplists then brands
 *   wp dataflair sync --only=toplists
 *   wp dataflair sync --only=brands
 *
 * Exits non-zero when a sync fails, so cron and CI can react. Every contract
 * safety gate applies exactly as it does from the admin button: nothing is
 * written until the response validates, and a paused sync reports the reason.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Cli;

use DataFlair\Toplists\Sync\SyncRequest;
use DataFlair\Toplists\Sync\SyncResult;

final class SyncCommand
{
    /** Hard stop so a broken pagination contract cannot loop forever. */
    private const MAX_PAGES = 500;

    /** @var callable(): object */
    private $toplistServiceResolver;

    /** @var callable(): object */
    private $brandServiceResolver;

    /**
     * Resolvers are injected so the command is testable without WordPress.
     *
     * @param callable(): object|null $toplistServiceResolver
     * @param callable(): object|null $brandServiceResolver
     */
    public function __construct(?callable $toplistServiceResolver = null, ?callable $brandServiceResolver = null)
    {
        $this->toplistServiceResolver = $toplistServiceResolver ?? static function () {
            return \DataFlair_Toplists::get_instance()->cli_toplist_sync_service();
        };
        $this->brandServiceResolver = $brandServiceResolver ?? static function () {
            return \DataFlair_Toplists::get_instance()->cli_brand_sync_service();
        };
    }

    /**
     * @param array<int, string>      $args
     * @param array{only?: string}    $assoc
     */
    public function __invoke(array $args, array $assoc): void
    {
        $only = strtolower(trim((string) ($assoc['only'] ?? 'all')));
        if (!in_array($only, ['all', 'toplists', 'brands'], true)) {
            $this->error('--only must be one of: toplists, brands, all.');
            return;
        }

        $failed = false;

        if ($only === 'all' || $only === 'toplists') {
            $failed = !$this->runStream('toplists', ($this->toplistServiceResolver)()) || $failed;
        }
        if ($only === 'all' || $only === 'brands') {
            $failed = !$this->runStream('brands', ($this->brandServiceResolver)()) || $failed;
        }

        if ($failed) {
            $this->error('Sync finished with failures. Run `wp dataflair logs --level=warning` for detail.');
            return;
        }

        $this->success('Sync complete.');
    }

    /**
     * Page through one sync stream. Returns false on the first failure.
     */
    private function runStream(string $stream, object $service): bool
    {
        $page     = 1;
        $lastPage = 1;
        $synced   = 0;
        $errors   = 0;
        $guard    = 0;

        do {
            $request = $stream === 'toplists' ? SyncRequest::toplists($page) : SyncRequest::brands($page);
            /** @var SyncResult $result */
            $result = $service->syncPage($request);

            if (!$result->success) {
                $this->warning(ucfirst($stream) . ' sync stopped on page ' . $page . ': ' . $result->message);
                return false;
            }

            $lastPage = max(1, $result->lastPage);
            $synced  += $result->synced;
            $errors  += $result->errors;

            // A partial page must be retried, not skipped, or rows are lost.
            $page = $result->partial ? $page : $page + 1;

            if (++$guard >= self::MAX_PAGES) {
                $this->warning(ucfirst($stream) . ' sync aborted after ' . self::MAX_PAGES . ' requests without completing.');
                return false;
            }
        } while ($page <= $lastPage);

        $this->log(ucfirst($stream) . ': ' . $synced . ' synced, ' . $errors . ' error(s), ' . $lastPage . ' page(s).');

        return true;
    }

    private function log(string $message): void
    {
        if (class_exists('\WP_CLI')) {
            \WP_CLI::log($message);
        }
    }

    private function warning(string $message): void
    {
        if (class_exists('\WP_CLI')) {
            \WP_CLI::warning($message);
        }
    }

    private function success(string $message): void
    {
        if (class_exists('\WP_CLI')) {
            \WP_CLI::success($message);
        }
    }

    private function error(string $message): void
    {
        if (class_exists('\WP_CLI')) {
            \WP_CLI::error($message);
        }
    }
}
