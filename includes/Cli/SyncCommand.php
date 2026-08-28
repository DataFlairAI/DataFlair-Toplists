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

class SyncCommand
{
    /** Hard stop so a broken pagination contract cannot loop forever. */
    private const MAX_PAGES = 500;

    /** Seconds to wait before each rate-limit retry of the same page. */
    private const RATE_LIMIT_BACKOFF = [20, 40, 60];

    /** @var callable(): object */
    private $toplistServiceResolver;

    /** @var callable(): object */
    private $brandServiceResolver;

    /** @var callable(string, string): void */
    private $reporter;

    /**
     * Resolvers are injected so the command is testable without WordPress.
     *
     * @param callable(): object|null $toplistServiceResolver
     * @param callable(): object|null $brandServiceResolver
     */
    public function __construct(
        ?callable $toplistServiceResolver = null,
        ?callable $brandServiceResolver = null,
        ?callable $reporter = null
    ) {
        // Injected so the reporting contract is observable in tests without
        // defining a global WP_CLI class, which would change behaviour for
        // every other command's tests in the same process.
        $this->reporter = $reporter ?? static function (string $level, string $message): void {
            if (!class_exists('\WP_CLI')) {
                return;
            }
            match ($level) {
                'warning' => \WP_CLI::warning($message),
                'success' => \WP_CLI::success($message),
                'error'   => \WP_CLI::error($message),
                default   => \WP_CLI::log($message),
            };
        };

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
     * Page through one sync stream, mirroring the browser driver in
     * assets/admin.js so a scheduled sync behaves exactly like the admin
     * button. Returns false when the run did not fully succeed.
     */
    private function runStream(string $stream, object $service): bool
    {
        $page     = 1;
        $lastPage = null;
        $synced   = 0;
        $errors   = 0;
        $skipped  = [];
        $guard    = 0;
        $rateLimitRetries = 0;

        while (true) {
            $request = $stream === 'toplists' ? SyncRequest::toplists($page) : SyncRequest::brands($page);
            /** @var SyncResult $result */
            $result  = $service->syncPage($request);

            if (!$result->success) {
                // A CLI run issues pages back to back with none of the
                // network latency that paces the browser driver, so it can
                // trip a per-minute API rate limit the admin button never
                // hits. Unlike a browser request, a cron job can afford to
                // wait, so back off and retry the same page instead of
                // failing a run that would otherwise complete.
                if ($rateLimitRetries < count(self::RATE_LIMIT_BACKOFF) && $this->isRateLimited($result->message)) {
                    $wait = self::RATE_LIMIT_BACKOFF[$rateLimitRetries];
                    $rateLimitRetries++;
                    $this->log(
                        ucfirst($stream) . ': rate limited on page ' . $page
                        . '; waiting ' . $wait . 's before retrying (attempt ' . $rateLimitRetries . ').'
                    );
                    $this->sleep($wait);
                    continue;
                }

                $this->warning(ucfirst($stream) . ' sync stopped on page ' . $page . ': ' . $result->message);
                return false;
            }
            $rateLimitRetries = 0;

            $payload = $result->toArray();

            // Fix the page count from the FIRST response only. The per-ID
            // fallback synthesises last_page as page+1 when it cannot learn
            // the real total, so re-reading it every iteration would walk the
            // bound forward forever against a failing endpoint.
            if ($lastPage === null) {
                $lastPage = max(1, (int) ($payload['last_page'] ?? 1));
            }

            $synced += $result->synced;
            $errors += $result->errors;
            if (!empty($payload['skipped'])) {
                $skipped[] = $page;
            }

            if (!empty($payload['is_complete'])) {
                break;
            }

            // next_page comes from toArray(), not the typed property: the
            // per-ID fallback overrides it there and always reports partial,
            // so trusting `partial` alone would re-request the same page.
            $next = isset($payload['next_page']) ? (int) $payload['next_page'] : $page + 1;
            if ($next <= $page && empty($payload['partial'])) {
                $next = $page + 1; // a non-partial page must always advance
            }
            $page = $next;

            if ($page > $lastPage) {
                break;
            }

            if (++$guard >= self::MAX_PAGES) {
                $this->warning(ucfirst($stream) . ' sync aborted after ' . self::MAX_PAGES . ' requests without completing.');
                return false;
            }
        }

        $this->log(
            ucfirst($stream) . ': ' . $synced . ' synced, ' . $errors . ' error(s), ' . $lastPage . ' page(s).'
        );

        // A skipped page means rows were never fetched. Reporting success
        // there would tell a cron everything is fine while part of the
        // catalogue silently goes stale, which is the whole failure mode
        // this release exists to prevent.
        if ($skipped !== []) {
            $this->warning(
                ucfirst($stream) . ': page(s) ' . implode(', ', $skipped)
                . ' were skipped after repeated upstream errors and hold no fresh data. They retry on the next full sync.'
            );
            return false;
        }

        return $errors === 0;
    }

    private function isRateLimited(string $message): bool
    {
        return str_contains($message, '429') || stripos($message, 'rate limit') !== false;
    }

    /** Seam so tests do not actually wait. */
    protected function sleep(int $seconds): void
    {
        sleep($seconds);
    }

    private function log(string $message): void
    {
        ($this->reporter)('log', $message);
    }

    private function warning(string $message): void
    {
        ($this->reporter)('warning', $message);
    }

    private function success(string $message): void
    {
        ($this->reporter)('success', $message);
    }

    private function error(string $message): void
    {
        ($this->reporter)('error', $message);
    }
}
