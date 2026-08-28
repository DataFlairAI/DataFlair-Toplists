# Upgrading DataFlair Toplists

This document covers breaking-change windows and migration steps between
major and minor versions. For day-to-day changelog entries see
`plugins_api_info` in `dataflair-toplists.php` or the release notes on
GitHub.

---

## v2.2.x → v2.3.0 (API contract safety)

**This is not a breaking change.** Update whenever it suits you. There is
nothing to configure, no new settings, and no code changes required. 2.3.0
works against the current DataFlair backend and the new one.

### What changed, in one paragraph

Your site renders toplists from its own WordPress database, not live from the
DataFlair API. A sync fills that database. Before 2.3.0, a sync would clear the
local tables and then fetch, and it trusted whatever it got back. 2.3.0 flips
that around: it fetches first, checks that the response still looks like the
data your site renders from, and only then touches local data. If anything is
wrong, it stops and leaves your existing data in place.

### What you will see if something goes wrong

A red notice at the top of wp-admin: **"DataFlair sync is paused: ..."** Your
pages keep serving the last successfully synced data the whole time. The notice
tells you which of two things happened:

| Notice says | What it means | What to do |
|---|---|---|
| "Update the plugin to version X or newer" | The backend no longer serves the API contract this plugin version was built for. | Update the plugin. |
| Anything else (a named field, a safety stop) | The backend changed a field this plugin renders from, or returned an empty result set. | Tell DataFlair. This is a backend bug, not yours. |

The notice clears itself on the next successful sync. There is nothing to
dismiss and no state to clean up.

To check the current state at any time: **DataFlair → Tools → API Contract
Check**. It probes the live API and reports one of `pass`, `fail` (with the
reason), or "passes but sync is still paused, run a full sync to clear it".

For automated monitoring, `GET /wp-json/dataflair/v1/health` returns a
`contract_mismatch` field, non-null while any sync stream is paused. The route
requires a `manage_options` account, so use an Application Password.

### What affects you and what does not

**Does not affect you:**

- DataFlair adding new fields to the API. The plugin ignores fields it does not
  know about. This is the normal case for most backend releases.
- DataFlair releasing `/api/v2`. Your plugin stays pinned to the version it was
  built for. You move only when you deliberately install a plugin version that
  uses the new one.
- Any DataFlair deploy, at the moment it happens. Nothing on your site changes
  until a sync runs, and a sync that finds a problem changes nothing at all.

**Affects you:**

- A DataFlair change that renames, removes, or retypes a field inside `/api/v1`.
  This is a contract violation on their side and is supposed to ship as a new
  API version instead. If it ever happens, your sync pauses loudly rather than
  storing data your pages cannot render.
- Backend downtime. Your sync fails, your pages keep serving the last good data,
  and they go stale until the backend returns.

### Running syncs from the command line

The plugin ships **no cron**, by design: nothing may change your data without
a deliberate trigger. From 2.3.0 there is a supported way to automate that
trigger:

```bash
wp dataflair sync                  # toplists, then brands
wp dataflair sync --only=toplists
wp dataflair sync --only=brands
```

It exits non-zero on failure, so a real system cron or a CI job can react. Use
that rather than WP-cron, which fires on visitor page views and would run a
multi-minute sync on an unlucky visitor's request.

Every safety gate described above applies identically here: nothing is written
until the response validates, and a paused sync reports the reason and exits
non-zero.

### How you find out when the DataFlair API changes

You asked to be told when the API version moves rather than discovering it
through a failure. Each full sync now reads DataFlair's `/api/vN/meta`
endpoint, which publishes the contract revision and which API versions are
served, and raises a **dismissible informational notice** when either changes:

```
┌────────────────────────────────────────────────────────────────────────────┐
│  DataFlair API update: The DataFlair API contract moved from 1.0.0 to      │
│  1.1.0. Changes within a version are additive, so your site keeps working  │
│  and there is nothing to do.  Dismiss                                      │
└────────────────────────────────────────────────────────────────────────────┘
```

```
┌────────────────────────────────────────────────────────────────────────────┐
│  DataFlair API update: A newer API version is now available (v2). This     │
│  plugin stays on v1 until you install a plugin version that uses the newer │
│  one, so nothing changes for you today.  Dismiss                           │
└────────────────────────────────────────────────────────────────────────────┘
```

Three deliberate properties:

- **Never an error.** Both cases are safe by policy, so this is a blue info
  notice and not the red paused-sync one. It never asks you to act.
- **The first reading is silent.** Establishing "the API is at 1.0.0" on
  upgrade day is noise, not news, so nothing is announced until something
  actually moves.
- **Old backends are ignored.** A DataFlair deployment without `/meta` yields
  nothing and changes no behaviour.

### Every failure mode, and what happens to your data

The column that matters is the last one. In every case, the data your site is
already serving survives. There is no failure mode where a DataFlair-side
problem empties your pages.

| What goes wrong | What you see | Your data |
|---|---|---|
| Backend down, DNS failure, or timeout | Retried twice with backoff, then a per-item fallback, then a clear error | untouched |
| HTTP 500 | "Server error (500)... a server-side issue, not a plugin configuration problem... Contact DataFlair support if this persists." | untouched |
| HTTP 502 / 503 / 504 (deploy in progress) | Retried, then "Server unavailable... could be a deployment in progress... Try again in a few minutes." | untouched |
| API token expired or revoked | Names the cause and tells you to generate a new one in DataFlair > Configuration > API Credentials. Also detects the wrong token type (`dfk_` instead of `dfp_`) and stray whitespace from copy-paste. | untouched |
| Site behind HTTP Basic Auth (staging) | Detects it and tells you to fill in HTTP Auth Username and Password in plugin settings, and that those are web server credentials, not your API token. | untouched |
| Token permissions or scope removed | "your token does not have permission... Check that your API credential has the correct permissions and is marked as active." | untouched |
| Endpoint moved, or wrong API Base URL | "Endpoint not found (404)... Expected format: https://tenant.dataflair.ai/api/v1" plus the URL you currently have configured. | untouched |
| Rate limited (429) | "Wait a few minutes and try again, or check your API credential rate limit settings." | untouched |
| HTML returned instead of JSON (login wall, redirect) | Detected specifically, and explains the web server is blocking the request before it reaches the API. | untouched |
| Response larger than 15 MB | Hard cap with a structured error. No memory exhaustion. | untouched |
| Malformed JSON | "JSON decode error" | untouched |
| `data` is no longer an array | Stopped before the destructive phase. | untouched |
| Empty payload against a populated site | Safety stop (see above). | untouched |
| A field renamed, removed, or retyped, detected on the first page | Contract canary names the field and stops before anything is written. | untouched |
| The same, detected on a later page of a run | Recorded and surfaced in the admin notice, and the run finishes. Aborting mid-run would leave you with a partial catalogue, which is worse than a complete one with some degraded fields. | replaced by this run's data |
| A field added | Ignored silently. This is the normal case for most backend releases. | fine |
| JSON key order or item order changed | No effect. Keys are read by name, and display order comes from the explicit `position` field. | fine |
| Response so slow it burns the sync time budget | The stale-row cleanup is skipped for that run and records are updated in place instead. | preserved |

### If you render from your own code instead of the shortcode or block

Some sites install this plugin purely as a sync engine and render everything
from their own theme functions, reading the synced data directly rather than
using the `[dataflair_toplist]` shortcode or the Gutenberg block. If that is
you, here is exactly what protects you and what does not:

| Protection | Applies to you? |
|---|---|
| Contract handshake, the 409 pause, and the admin notice | **Yes.** These live in the sync layer. |
| Contract canary (drift stopped before anything is written) | **Yes, for the toplists stream**, which is what keeps drifted toplist data out of the database you read from. The brands stream gets the 409 handshake, the empty-payload safety stop, and the fail-safe wipe order, but no field-level canary; brand fields are covered by DataFlair's CI lock on the v1 contract. |
| Fail-safe wipe order and the empty-payload safety stop | **Yes.** Your local data survives every backend failure. |
| Drift-hardened rendering (templates degrade instead of erroring) | **No.** That hardening lives in the plugin's own card and table templates. Your rendering code is yours, and a retyped field will fail in it the way it would in any PHP. |

Two consequences worth planning for:

1. **Guard your own reads.** Treat the synced payload as external input: check
   `is_array()` before looping, and avoid passing values straight into
   `trim()`, `md5()`, or string casts. A field that changes type is the case
   that bites custom integrations hardest, and the plugin cannot guard code it
   does not control.
2. **The canary checks the fields the plugin renders, not the fields you use.**
   If your code depends on a v1 field the plugin never touches, the canary will
   not notice it disappearing. What covers you there is DataFlair's own CI,
   which locks the entire v1 field set. That is why the rule below matters.

### The database tables are a contract, and they follow the same rule

If you read the plugin's tables directly, those column names are as much a
contract as the API is. From 2.3.0 they are treated that way and locked by a
test in the plugin's own CI, so a rename fails our build instead of your site.

**The promise: additive only inside a major version.** New columns may appear.
An existing column will not be renamed, removed, or repurposed without a
documented migration in this file and a major version bump.

`wp_dataflair_toplists`

```
id  api_toplist_id  name  slug  current_period  published_at
item_count  locked_count  sync_warnings  data  version  last_synced
```

`wp_dataflair_brands`

```
id  api_brand_id  name  slug  status  product_types  licenses  top_geos
offers_count  trackers_count  classification_types  review_url_override
is_disabled  data  last_synced
```

`wp_dataflair_alternative_toplists`

```
id  toplist_id  geo  alternative_toplist_id  created_at  updated_at
```

The `data` column holds the **verbatim** API response for that record. That is
also locked by a test: it will keep storing the raw payload rather than a
transformed shape, so anything you parse out of it stays parseable. The fields
*inside* that JSON are governed by the `/api/v1` contract described above.

Two practical notes:

- **Read `api_toplist_id` / `api_brand_id`, not `id`.** The `id` column is a
  local auto-increment that is not stable across a full resync; the `api_*`
  columns are the upstream identifiers and are stable.
- **Rows are replaced on a full sync.** Do not store your own data in these
  tables or add your own columns to them; keep anything of your own in a
  separate table keyed by `api_toplist_id` or `api_brand_id`.

### If you have custom code reading the payload

Two integration points hand you raw API data, and neither is covered by the
plugin's internal hardening, because your code is outside it:

- the `dataflair_review_url` filter, which receives the raw `$brand` and `$item`
  arrays;
- the `data` column of `wp_dataflair_toplists`, which stores the API response
  verbatim.

Both are safe to use, with one rule: **only depend on fields that exist in
`/api/v1` today.** Every field in the v1 response is locked by a snapshot test
in DataFlair's CI, so a rename or removal fails their build before it can reach
you. That guarantee covers the whole v1 contract, including fields this plugin
never renders. It does not cover anything outside v1.

If your custom code needs to know when sync is paused, hook
`dataflair_sync_item_failed` or read the health endpoint above.

---

## v1.15.x → v2.0.0

### What changed

`DataFlair\Toplists\Plugin::boot()` is now the canonical entry point for
the plugin. The plugin file calls `Plugin::boot()` directly at load time;
that method is idempotent and internally constructs the legacy
`DataFlair_Toplists` singleton so every existing hook registration, admin
page, shortcode, block, and REST route keeps working byte-for-byte.

`DataFlair_Toplists::get_instance()` is **deprecated but functional** on
the entire v2.0.x line. It is scheduled for removal in **v2.1.0**, at
which point calling it will throw `BadMethodCallException`.

A hand-written lazy service container ships as
`DataFlair\Toplists\Container`. The canonical entry exposes it via
`Plugin::boot()->container()`. Today the container wires one service —
`logger` — resolving to the `LoggerInterface` the Phase 1 factory
returns. More services join during the v2.0.x line as one-off `new Foo()`
call sites migrate.

### What downstream consumers need to do

If you are **not** calling `DataFlair_Toplists::get_instance()` from your
theme, child plugin, or mu-plugin, **no action is required**.

If you **are** calling `DataFlair_Toplists::get_instance()`, you have the
entire v2.0.x line to migrate. The recommended pattern:

```php
// Legacy (v1.x → v2.0.x, works but deprecated)
$legacy = DataFlair_Toplists::get_instance();

// Canonical (v2.0.0+)
$plugin    = \DataFlair\Toplists\Plugin::boot();
$container = $plugin->container();
$logger    = $container->get('logger');
```

### Turning on strict deprecation notices

By default `DataFlair_Toplists::get_instance()` does **not** emit
`E_USER_DEPRECATED`. This is intentional — the god-class continues to
own WordPress hook registrations internally, and firing a deprecation
notice on every hook dispatch would drown `error_log`.

To opt in to strict notices (useful when auditing your own downstream
code for leftover references):

```php
add_filter('dataflair_strict_deprecation', '__return_true');
```

With this filter enabled, every call to
`DataFlair_Toplists::get_instance()` emits a deprecation notice pointing
at `\DataFlair\Toplists\Plugin::boot()`.

### Overriding container services

The container exposes `register()`, `set()`, `get()`, and `has()`. The
most common override is swapping the logger:

```php
add_action('plugins_loaded', static function () {
    $plugin = \DataFlair\Toplists\Plugin::instance();
    if ($plugin === null) {
        return;
    }
    $plugin->container()->set('logger', new MySentryLogger());
}, 11); // run after the plugin file has loaded
```

`Plugin::instance()` returns `null` before `Plugin::boot()` has run, so
either check for `null` or attach your override inside
`plugins_loaded` / `init` so the boot has already happened.

### Why a major version bump

Three reasons:

1. A new canonical public API (`Plugin::boot()`) becomes the supported
   contract going forward.
2. The formal deprecation window opens on `DataFlair_Toplists` — a class
   that has been the plugin's entry point since v1.0.
3. Downstream integrators should plan their migration within the v2.0.x
   line so the shim drop in v2.1.0 doesn't surprise them.

No runtime behaviour changed for end users (editors, site admins,
visitors). Every hook, option, table, shortcode, block, REST route, and
AJAX action is preserved.

---

## v2.0.x → v2.1.0

### What changed

Strict deprecation warnings on `DataFlair_Toplists::get_instance()` flip
from **opt-in** (v2.0.0) to **default-on** (v2.1.0). Any call to the
legacy entry point from outside `DATAFLAIR_PLUGIN_DIR` now emits
`E_USER_DEPRECATED` once per unique caller file/line per request,
pointing to `\DataFlair\Toplists\Plugin::boot()`.

The class symbol itself is preserved. Every existing hook registration,
admin page, shortcode, block, REST route, and AJAX action continues to
work byte-for-byte — nothing breaks at runtime.

### What downstream consumers need to do

Option 1 — **migrate**. The recommended pattern (same as v2.0.0):

```php
$plugin    = \DataFlair\Toplists\Plugin::boot();
$container = $plugin->container();
$logger    = $container->get('logger');
```

Option 2 — **silence temporarily**. If you need more time to port call
sites, silence the notice without breaking anything:

```php
add_filter('dataflair_strict_deprecation', '__return_false');
```

This opt-out remains supported for the entire v2.1.x line. The filter
stops firing in v3.0.0 when the class symbol itself is scheduled for
removal.

### Internal caller filtering — what's filtered

The god-class still calls `get_instance()` internally during hook
dispatch, and extracted `src/` classes still walk through the singleton
during the strangler-fig transition. Emitting on every such call would
drown `error_log`. v2.1.0 filters out any caller whose stack-frame `file`
begins with `DATAFLAIR_PLUGIN_DIR`, so only genuine downstream callers
(your theme, your child plugin, your mu-plugin) see the notice.

---

## Planned: v2.1.x → v3.0.0 (class-symbol removal)

The `DataFlair_Toplists` symbol is scheduled for full removal in v3.0.0.
After the drop:

- `DataFlair_Toplists::get_instance()` is undefined. Calling it throws
  `Error: Class "DataFlair_Toplists" not found`.
- The `dataflair_strict_deprecation` opt-out filter no longer fires —
  the class it silenced doesn't exist anymore.
- All implementation in `dataflair-toplists.php` moves to the `src/`
  namespace tree.
- The plugin file shrinks to a thin bootstrap that calls
  `\DataFlair\Toplists\Plugin::boot()` and does nothing else.

The v2.1.x point releases will each extract a small batch of the
remaining god-class methods (shortcode, schema upgrades, DB helpers)
into `src/` equivalents. Every extraction is additive — the class
symbol stays, the extracted methods stay as thin delegators.

When every method has been extracted and the class body is empty (or
near-empty), v3.0.0 ships the final removal.

Plan your migration in v2.1.x. The v2.1.x line is explicitly a
migration window — nothing else about it will change.
