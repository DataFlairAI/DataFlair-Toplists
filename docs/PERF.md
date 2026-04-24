# DataFlair Toplists — Perf Rig

Phase 0.5 gives the plugin a local perf rig and a CI perf gate. The rig exists to **prove** that Phase 0A + 0B extinguished the Sigma OOM and to catch regressions in any later phase **before** they hit production.

Without this rig, we have no way to know that an extracted renderer, a repository refactor, or a schema change has re-introduced the problem until a real site goes down. The rig's job is to be a hard, mechanical, pre-deploy answer.

## Thresholds (the gate)

The gate is run on **tier Sigma** (see below) against the **render** scenario and fails if any of these are breached:

| Metric | Threshold | Rationale |
|---|---|---|
| Peak RSS | **≤ 512 MB** | Sigma's PHP-FPM pool is 1 GB. Headroom of 2× is the target. |
| Wall time | **≤ 5 s** | A shortcode render that takes longer than 5 s is user-visible. |
| Memory limit | **1 GB** | Match production. The rig pins `memory_limit=1G` in `php -d`. |

These values are the Phase 0.5 defaults. They can be overridden per-run via env vars (see below) or per-PR via a dispatch input, but the CI gate is set to the defaults — changing them requires editing `.github/workflows/perf-gate.yml` in a separate reviewed PR.

## Tiers

The seeder generates deterministic synthetic rows per tier:

| Tier | Toplists | Items per toplist | Brands | Avg JSON/toplist | Use |
|---|---|---|---|---|---|
| **S**     | 10    | 5  | 50    | ~50 KB  | smoke tests, day-to-day dev |
| **Sigma** | 200   | 20 | 500   | ~200 KB | prod-like; the CI gate runs here |
| **L**     | 500   | 20 | 1,000 | ~200 KB | headroom check |
| **XL**    | 1,000 | 20 | 2,000 | ~200 KB | stress |
| **P**     | 2,000 | 30 | 5,000 | ~500 KB | punishing (reserved for pre-release) |

## Scenarios

| Scenario | What it exercises |
|---|---|
| `render` | `do_shortcode('[dataflair_toplist id=…]')` for every seeded toplist. |
| `rest`   | `/wp-json/dataflair/v1/toplists/{id}/casinos?page=1&per_page=20` for every toplist. |
| `admin`  | Emulates the brands admin page projection, 50/page. |
| `sync`   | Currently re-uses the render scenario; extend when sync extraction lands. |

## Running locally

You need a working WP install and `wp` on PATH. The default is `/var/www/html`; override with `DATAFLAIR_PERF_WP_PATH`.

```bash
# Seed once per branch / code change:
wp dataflair perf:seed --tier=Sigma

# Run a scenario:
wp dataflair perf:run --tier=Sigma --scenario=render

# End-to-end via composer (matches what CI runs):
composer perf
```

`composer perf` falls back to a no-op with a hint message if `wp` is not on PATH, so it never hard-fails on dev laptops without WP-CLI. CI enforces unconditionally.

## Reading the probe output

The `mu-plugins/dataflair-perf-probe.php` MU-plugin emits a single line per request to stderr:

```
[DataFlair-Perf] uri=<uri> peak_mb=4.5 wall_s=0.012 queries=3
```

- **`uri`** — request identifier (`wp-cli`, `rest:/wp-json/...`, `ajax:<action>`, or frontend URI).
- **`peak_mb`** — `memory_get_peak_usage(true) / 1024 / 1024`, rounded.
- **`wall_s`** — time since request init.
- **`queries`** — `$wpdb->num_queries` at shutdown.

`wp dataflair perf:run` also prints an aggregated one-liner at the end with the same fields across the whole scenario, plus an `items=<n>` count.

## Fatal reproduction

To prove the rig catches the original fire, check out a pre-Phase-0A commit and run the render scenario under a 1 GB memory limit — you should see an OOM at `wp_check_filetype_and_ext()` during brand-logo sideload. On any Phase-0A+ commit, the same scenario completes well under the 512 MB gate.

```bash
# Pre-fix — should OOM:
git switch <pre-phase-0a-commit>
composer perf  # fails

# Post-fix — passes:
git switch epic/refactor-april
composer perf  # green
```

## Environment variables

All optional. Defaults shown.

| Variable | Default | Notes |
|---|---|---|
| `DATAFLAIR_PERF_WP_PATH`      | `/var/www/html` | Path to WP core. |
| `DATAFLAIR_PERF_TIER`         | `Sigma`         | One of S, Sigma, L, XL, P. |
| `DATAFLAIR_PERF_SCENARIO`     | `render`        | One of render, rest, admin, sync. |
| `DATAFLAIR_PERF_MAX_RSS_MB`   | `512`           | Gate threshold (MB). |
| `DATAFLAIR_PERF_MAX_WALL_S`   | `5`             | Gate threshold (seconds). |
| `DATAFLAIR_PERF_MEMORY_LIMIT` | `1G`            | `php -d memory_limit=…`. |

## Skipping the gate

For docs-only PRs and for the rare case of a known-flaky gate under active investigation, add the label `skip-perf-gate` to the PR. The CI job's `if:` expression checks for it.

Never leave the label on a PR that changes plugin code. If the gate is genuinely wrong, **fix the gate** in a separate PR — don't normalise bypassing it.
