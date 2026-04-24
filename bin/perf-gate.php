<?php
/**
 * bin/perf-gate.php — Phase 0.5 perf-gate driver.
 *
 * Invoked by `composer perf` and by the GitHub Actions perf-gate workflow.
 *
 * Expects a working WP installation at $DATAFLAIR_PERF_WP_PATH with this
 * plugin already active. Runs:
 *
 *   wp dataflair perf:seed --tier=$DATAFLAIR_PERF_TIER --quiet
 *   wp dataflair perf:run  --tier=$DATAFLAIR_PERF_TIER \
 *                          --scenario=$DATAFLAIR_PERF_SCENARIO \
 *                          --max-rss-mb=$DATAFLAIR_PERF_MAX_RSS_MB \
 *                          --max-wall-s=$DATAFLAIR_PERF_MAX_WALL_S
 *
 * Exits non-zero on breach. Documented thresholds live in docs/PERF.md.
 *
 * Environment variables (all optional — defaults shown):
 *   DATAFLAIR_PERF_WP_PATH       /var/www/html
 *   DATAFLAIR_PERF_TIER          Sigma
 *   DATAFLAIR_PERF_SCENARIO      render
 *   DATAFLAIR_PERF_MAX_RSS_MB    512
 *   DATAFLAIR_PERF_MAX_WALL_S    5
 *   DATAFLAIR_PERF_MEMORY_LIMIT  1G
 *
 * Uses proc_open with an argv array (no shell interpolation) so env-var
 * inputs cannot be mis-escaped into a shell-injection.
 */

declare(strict_types=1);

$env = static function (string $name, string $default): string {
    $v = getenv($name);
    return is_string($v) && $v !== '' ? $v : $default;
};

$wp_path   = $env('DATAFLAIR_PERF_WP_PATH',      '/var/www/html');
$tier      = $env('DATAFLAIR_PERF_TIER',         'Sigma');
$scenario  = $env('DATAFLAIR_PERF_SCENARIO',     'render');
$max_rss   = $env('DATAFLAIR_PERF_MAX_RSS_MB',   '512');
$max_wall  = $env('DATAFLAIR_PERF_MAX_WALL_S',   '5');
$mem_limit = $env('DATAFLAIR_PERF_MEMORY_LIMIT', '1G');

// Locate wp-cli via PATH by shelling out to /usr/bin/env (no user input,
// no shell interpolation, exit 1 if not present).
$wp_bin = locate_binary('wp');
if ($wp_bin === '') {
    fwrite(STDERR, "[perf-gate] WP-CLI not installed on PATH — skipping. Install wp-cli to run the perf gate locally. CI runs it unconditionally.\n");
    exit(0);
}

$seed_exit = run_argv([
    'php', '-d', "memory_limit={$mem_limit}",
    $wp_bin, "--path={$wp_path}",
    'dataflair', 'perf:seed', "--tier={$tier}", '--quiet',
]);
if ($seed_exit !== 0) {
    fwrite(STDERR, "[perf-gate] seed failed (exit {$seed_exit}).\n");
    exit($seed_exit);
}

$run_exit = run_argv([
    'php', '-d', "memory_limit={$mem_limit}",
    $wp_bin, "--path={$wp_path}",
    'dataflair', 'perf:run',
    "--tier={$tier}", "--scenario={$scenario}",
    "--max-rss-mb={$max_rss}", "--max-wall-s={$max_wall}",
]);

exit($run_exit);

/**
 * Resolve a named binary on PATH without invoking a shell.
 */
function locate_binary(string $name): string
{
    $path_env = (string) (getenv('PATH') ?: '');
    foreach (explode(PATH_SEPARATOR, $path_env) as $dir) {
        if ($dir === '') { continue; }
        $candidate = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }
    return '';
}

/**
 * @param array<int, string> $argv
 */
function run_argv(array $argv): int
{
    fwrite(STDOUT, "[perf-gate] > " . implode(' ', $argv) . "\n");
    $proc = proc_open(
        $argv,
        [
            0 => ['pipe', 'r'],
            1 => STDOUT,
            2 => STDERR,
        ],
        $pipes
    );
    if (!is_resource($proc)) {
        fwrite(STDERR, "[perf-gate] proc_open failed\n");
        return 127;
    }
    fclose($pipes[0]);
    return (int) proc_close($proc);
}
