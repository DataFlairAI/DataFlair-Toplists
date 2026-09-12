# DataFlair Toplists for ASP.NET — Port Plan

> **Status:** parked, not started
> **Created:** 2026-08-18
> **Owner:** Mex
> **Touches:** new repo `DataFlair-Toplists-Net` (+ read-only reference to this repo)
> **First target site:** cricketworld.com (custom CMS on ASP.NET, .NET Framework 4.x)
> **Branches when picked up:** new repo, one PR per phase

---

## Goal

Ship the DataFlair Toplists product to publishers who are **not** on WordPress — starting with
cricketworld.com, which runs a custom CMS on ASP.NET. Same DataFlair API, same synced data, same
casino/sportsbook cards, same geo rules, same affiliate redirect. Different host platform.

This document is the port plan: what the WordPress plugin actually does, what each piece maps to on
ASP.NET, what genuinely cannot be ported 1:1, and the order to build it in.

---

## First: ASP.NET has no plugin system

There is no `wp-content/plugins/` equivalent. ASP.NET is a web framework, not a CMS, so there is no
host-defined extension contract to write against. What exists instead:

| ASP.NET mechanism | What it is | Fit for us |
|---|---|---|
| **Class library (`.dll`) dropped in `bin/`** | Any assembly in `bin/` is loaded by the app. Distributed as a NuGet package. | ✅ This is the closest thing to "a plugin". |
| **`IHttpModule`** | Intercepts every request in the pipeline (`web.config` registration). | ✅ Affiliate redirect, content-token rewriting. |
| **`IHttpHandler` / route** | Owns a URL. | ✅ `/go/`, admin pages, JSON API. |
| **Web Forms `UserControl` / `WebControl`** | `<df:Toplist ToplistId="123" runat="server" />` in an `.aspx`. | ✅ If the CMS is Web Forms. |
| **MVC `HtmlHelper` / child action / `ViewComponent`** | `@Html.DataFlairToplist(123)` in a Razor view. | ✅ If the CMS is MVC. |
| **`VirtualPathProvider` / embedded resources** | Ship views and static assets inside the DLL. | ✅ Lets one NuGet package carry its own templates and CSS. |
| **Response filter (`Response.Filter`)** | Rewrites outgoing HTML. | ⚠️ This is the real "shortcode" analogue. Works everywhere, costs CPU. See §7. |
| **Separate site / microservice** | We host our own ASP.NET app; their pages embed it. | ✅ Zero CMS coupling — the fastest path to a live toplist. |

So the deliverable is: **a NuGet package (`DataFlair.Toplists`) that can run either inside their app
or as a standalone sidecar service** — one codebase, two hosting modes.

---

## Target-stack read (cricketworld.com)

From the fingerprint provided, plus what it implies:

| Signal | What it actually tells us | Consequence for us |
|---|---|---|
| `Microsoft ASP.NET 4.0.30319` | This is the **CLR version** from the `X-AspNet-Version` header. CLR 4 covers .NET Framework 4.0 **through** 4.8. It does *not* tell us Web Forms vs MVC, nor the exact framework version. | Target **`netstandard2.0` + `net472`** multi-target so the assembly loads on 4.6.1+ and on modern .NET. Confirm the real framework version in Phase 0. |
| **Cloudflare** | `CF-IPCountry` header is available on every request. | 🎉 Geo-targeting ports **1:1** — `VisitorGeoResolver` already reads exactly this header. |
| **Cloudflare** (again) | Their HTML is almost certainly edge-cached at this traffic level. | ⚠️ **Biggest architectural risk in the whole port.** Geo-varying HTML behind a shared cache is wrong-by-default. See §8. |
| **Bootstrap** | Global CSS with generic class names. | Namespace all our CSS. Also: the current card template leaks Tailwind classes (see §11) — those must be replaced, not copied. |
| **jQuery 3.7 / Preact / Goober** | They already ship a modern JS runtime and CSS-in-JS. | An embed/hydrate widget is idiomatic for them. No Alpine.js — our `x-data`/`x-show` usage must be replaced (~40 lines of vanilla JS). |
| **Lozad.js** | They lazy-load images. | Brand logos should opt into the same mechanism (`class="lozad" data-src=…`) or use native `loading="lazy"`. |
| **Google Tag Manager / GA** | Affiliate click tracking will be expected to fire into GTM. | Add a `dataLayer.push` on CTA click — new requirement, not in the WP plugin. |
| **Highcharts / Raphael / Flickity / FancyBox** | Editorial/stats widgets. | Irrelevant, except as evidence that dropping third-party JS on pages is already normal there. |

**Not verifiable from here:** whether the CMS is Web Forms or MVC, what database it uses, and whether
we can deploy assemblies into it. `cricketworld.com` is blocked by this environment's network egress,
so none of that was probed. It is Phase 0 work (§4).

---

## 1. Feature inventory — what has to be ported

Taken from the actual v2.2.11 codebase, not the README.

### Sync (upstream → local)

| Capability | Where it lives today |
|---|---|
| Paged toplist sync with per-page batching | `Sync/ToplistSyncService::syncPage` |
| Per-ID fallback with progressive split (per_page 5 → 1) on bulk 5xx | `ToplistSyncService::syncPagePerId` |
| Brand sync with `status != Active` skip rule | `Sync/BrandSyncService` |
| Logo download at sync time (3 MB cap, 8 s timeout, 7-day reuse) | `Http/LogoDownloader`, `Sync/LogoSync` |
| Alternative (geo-fallback) toplists | `Sync/AlternativesSyncService` |
| 15 MB response cap, 12 s default timeout, exponential retry on 5xx | `Http/ApiClient` |
| Wall-clock budget with 3 s headroom, cooperative cancellation | `Support/WallClockBudget` |
| API base-URL autodetect from first 200 | `ToplistSyncService::syncPage`, `Http/ApiBaseUrlDetector` |
| Brands API v1/v2 switch | `Http/BrandsApiUrlBuilder` |
| HTTP Basic auth injection (staging sites) | `ApiClient` |
| Endpoint discovery + sync history + API health | `Sync/EndpointDiscovery`, `Sync/SyncHistoryRecorder` |
| Paginated DELETE on page 1 (never `TRUNCATE`) | `Database/PaginatedDeleter` |

### Storage

| Capability | Where |
|---|---|
| 3 tables: toplists, brands, alternative_toplists | `Database/SchemaMigrator` |
| JSON blob + sync-time extracted columns (`item_count`, `locked_count`, `sync_warnings`) | ditto |
| Generated+indexed columns: `external_id`, `list_template_id`, `geo_type`, `geo_code` | `ensureBrandsExternalIdIndex`, `ensureToplistsGeoVirtualColumns` |
| Incremental schema upgrades + self-heal on boot | `SchemaMigrator::checkDatabaseUpgrade` |

### Render

| Capability | Where |
|---|---|
| `[dataflair_toplist id/slug/title/limit/layout/ctaMode/template/auto_geo]` | `Frontend/Shortcode/ToplistShortcode` |
| Cards layout + table (accordion) layout | `Render/CardRenderer`, `Render/TableRenderer` |
| Stale-data banner after 3 days | `ToplistShortcode::STALE_AFTER_SECONDS` |
| Layer 1 geo render gate (default-deny) | `Geo/GeoRenderGate` |
| Layer 2 geo family auto-select cascade | `Geo/GeoFamilySelector` |
| Visitor country from `CF-IPCountry` / `X-Geoip-Country` / filter / admin `?dataflair_geo=` override | `Geo/VisitorGeoResolver` |
| Brand-meta prefetch (1–3 queries per toplist, not 5 per card) | `Render/BrandMetaPrefetcher`, `BrandMetaLookup` |
| Review-URL cascade: override → published review → `/reviews/{slug}/` → CTA | `CardRenderer::render` |
| Product-type-aware labels (casino / sportsbook / poker) | `ProductTypeLabels` |
| Pros/cons overrides keyed by stable brand/item ID | `Render/ProsConsResolver` |
| Promo-code copy-to-clipboard pill | `views/frontend/casino-card.php`, `Assets/PromoCopyScript` |
| `/go/?campaign=…` affiliate redirect (301, 404 on unknown) | `Frontend/Redirect/CampaignRedirectHandler` |
| Logo fallback chain (7 keys, nested object shapes) | `casino-card.php` |
| **Render is read-only** — no HTTP, no media writes, no post writes | enforced by `RenderIsReadOnlyTest` |

### Admin

Dashboard (API health, stat tiles, activity feed, scheduled jobs, shortcode-usage count) · Toplists
list (search, sort, bulk resync, bulk delete, per-row accordion with items + raw JSON) · Brands
(review-URL override inline edit, bulk disable, bulk apply review pattern, bulk resync) · Tools
(tests runner, log tail + download, API preview) · Settings (API connection + test, colour
customisations, sync schedule, geo kill switch) · dirty-state guard. **26 AJAX handlers** in
`src/Admin/Ajax/`.

### Editor / API / Ops

Gutenberg block with 25 attributes · 3 REST endpoints (`/toplists`, `/toplists/{id}/casinos`,
`/health`) · 4 WP-CLI commands · pluggable logging (file / error_log / Sentry / null) · perf rig with
a CI gate (Sigma tier: ≤512 MB peak, ≤5 s) · GitHub-release auto-update · i18n · uninstall cleanup.

---

## 2. Primitive-by-primitive port map

| WordPress primitive | ASP.NET equivalent | Notes |
|---|---|---|
| Plugin folder + activation hook | NuGet package + `IRegisteredObject` / startup task | No activation hook — run migrations on first request, idempotently, like `SchemaMigrator` already does. |
| `$wpdb` + `dbDelta` | **Dapper** + **DbUp** (or FluentMigrator) | EF Core ≥5 does **not** run on .NET Framework; EF Core 3.1 is EOL. Dapper is netstandard2.0 and fits both hosts. |
| `wp_options` | `df_settings` table (key/value) + `web.config` for secrets | API token goes in an **encrypted** config section (`aspnet_regiis -pe`) or the sidecar's env vars — never plaintext in a DB row a CMS admin can read. |
| Transients | `MemoryCache` + a `df_cache` table for cross-process entries | The tracker-campaign transient (30-day TTL) **must** be durable — it backs `/go/`. Table, not memory. |
| WP-Cron | **Out-of-process console runner** on Windows Task Scheduler (baseline); Hangfire in-process (opt-in) | IIS app-pool recycling kills in-process schedulers. See §9. |
| Shortcode | Content token rewritten by a response filter, **or** a control/helper call | See §7 — this is the least clean part of the port. |
| Gutenberg block | Admin "snippet builder" page + optional editor toolbar button | No block editor exists. See §7. |
| `wp-json/dataflair/v1/*` | `/api/dataflair/v1/*` (Web API 2 on Framework, minimal API on modern .NET) | Keep paths and payload shapes identical so tooling is portable. |
| Nonces | Anti-forgery tokens (`@Html.AntiForgeryToken()` / double-submit cookie) | Every admin POST. |
| `current_user_can('manage_options')` | `IPrincipal.IsInRole` via an `ICurrentUserContext` adapter | Their CMS owns auth. See §10. |
| Review CPT (`post_type=review`) | `IReviewUrlResolver` adapter over their article store | **The hardest integration point.** See §10. |
| Media library / `wp_handle_sideload` | Filesystem under a static-only path, or blob/CDN | Never a path where handlers execute. See §9. |
| Actions/filters (`dataflair_card_renderer`, …) | DI container + interfaces already present in `src/` | The plugin's `*Interface` seams port directly to C# interfaces. |
| `plugin-update-checker` (GitHub releases) | NuGet feed + manual "update available" tile | Auto-swapping a DLL recycles the app pool. Manual by default. |
| WP-CLI | `DataFlair.Toplists.Cli` console (`sync`, `reconcile-reviews`, `perf`, `logs`) | Same verbs. |
| PHPUnit + Brain Monkey | xUnit + NSubstitute; **NetArchTest** for the read-only render invariant | The read-only rule becomes a compile-time-ish architecture test. |

---

## 3. Three deployment modes (build them in this order)

### Mode A — Sidecar + embed (no CMS coupling) ← **start here**

We host `toplists.cricketworld.com` (or a path routed through Cloudflare). Their page carries:

```html
<div data-dataflair-toplist="123" data-limit="10"></div>
<script src="https://toplists.cricketworld.com/df.js" defer></script>
```

`df.js` (~3 KB, no framework — or Preact, which they already ship) fetches
`GET /render/123?limit=10` and injects server-rendered HTML.

- **Ships without touching their codebase** — the CMS only needs to allow a `<div>` + `<script>` in
  an article body, which every CMS allows.
- **Cache-safe by construction:** their HTML stays geo-neutral and fully edge-cacheable; only our
  fragment varies by country. This solves §8 outright.
- Costs: one extra request per page, a flash-of-empty-space unless we reserve height, and toplist
  content is not in the initial HTML for crawlers.
- SEO mitigation: emit a server-rendered `<noscript>` fallback plus JSON-LD from our endpoint, and/or
  let them server-include our fragment (Mode B) on pages where indexing the toplist matters.

### Mode B — In-process assembly (the real "plugin")

`DataFlair.Toplists.dll` in their `bin/`, `web.config` module + handler registration, tables in their
SQL Server, a control/helper/token for placement, admin under `/dataflair-admin/`.

- Full parity, server-rendered inline HTML, no extra request.
- Requires: deploy access, DB access, a release window, and their CMS's auth to be reusable.

### Mode C — Edge (Cloudflare Worker), optional

A Worker fetches our fragment and injects it via `HTMLRewriter` at the edge, keyed on `CF-IPCountry`.

- Best of both: server-rendered into the HTML, and the edge cache key includes the country.
- Requires Workers on their account and a change to their Cloudflare config.

**Recommendation:** A first (proves the data, the render, and the commercial value in weeks, not
months), B for pages that need the toplist in-HTML, C only if they want edge-injection later.

---

## 4. Phase 0 — discovery (blocking; ~2 days, mostly their answers)

Nothing below Phase 1 should be committed to before these are answered.

1. **Framework version** — `4.0` vs `4.5.x` vs `4.6.1+` vs `4.8`? (Decides `netstandard2.0` vs a
   `net45` fallback build. Check `<httpRuntime targetFramework>` in `web.config`.)
2. **Web Forms or MVC?** Any `.aspx`/`__VIEWSTATE`, or extensionless MVC routes? (Decides the
   placement API. Ship both adapters if mixed — many legacy CMSes are.)
3. **Database** — SQL Server version? (2016+ unlocks `JSON_VALUE` computed columns; 2012/2014 means
   we fall back to sync-time extracted columns only.) Can we create tables in their DB, or do we get
   our own?
4. **Deployment** — can we ship a DLL into `bin/`? Is there a build pipeline, or is it FTP-to-IIS?
   Is there a staging site?
5. **Content authoring** — how does an editor put a widget into an article today? Is the body HTML,
   markdown, or structured blocks? Which editor (TinyMCE / CKEditor / custom Preact)?
6. **Auth** — can we reuse their admin cookie/principal, or do we need standalone auth for our admin?
7. **Cloudflare** — plan tier (Enterprise = custom cache keys available), are HTML pages cached, are
   Workers available?
8. **Reviews** — do brand review pages exist on the site today? What is the URL pattern, and what
   table/API can we query to find "is there a published review for brand X"?
9. **Compliance** — which markets does the site serve, and which RG disclaimer/licence text is
   required per market? (The current template hardcodes UK `gambleaware.org`.)
10. **Traffic** — peak RPS on article pages, so we can size the cache/perf targets honestly.

---

## 5. Solution layout

```
DataFlair.Toplists.sln
├── src/
│   ├── DataFlair.Toplists.Core/          netstandard2.0  — domain, view models, geo, render pipeline
│   ├── DataFlair.Toplists.Data.SqlServer/ netstandard2.0 — Dapper repos, DbUp migrations
│   ├── DataFlair.Toplists.Http/          netstandard2.0  — API client, retry, budget, logo downloader
│   ├── DataFlair.Toplists.Sync/          netstandard2.0  — sync services (toplists, brands, alts)
│   ├── DataFlair.Toplists.Rendering/     netstandard2.0  — card/table renderers + embedded templates
│   ├── DataFlair.Toplists.Web/           net472          — HttpModule, handlers, WebForms control,
│   │                                                       MVC HtmlHelper, response filter
│   ├── DataFlair.Toplists.Web.Core/      net8.0          — middleware + TagHelper/ViewComponent
│   ├── DataFlair.Toplists.Admin/         net472;net8.0   — admin UI (Razor, self-contained assets)
│   ├── DataFlair.Toplists.Api/           net472;net8.0   — /api/dataflair/v1
│   ├── DataFlair.Toplists.Cli/           net8.0          — sync runner, reconcile, perf, logs
│   └── DataFlair.Toplists.Sidecar/       net8.0          — Mode A host (render endpoint + df.js)
└── tests/
    ├── DataFlair.Toplists.Tests.Unit/
    ├── DataFlair.Toplists.Tests.Architecture/   — NetArchTest: render layer touches no I/O types
    └── DataFlair.Toplists.Tests.Perf/           — BenchmarkDotNet + the Sigma-tier gate
```

**Hard rule:** everything above `*.Web*` is `netstandard2.0` and references **zero** `System.Web`
types. That is what makes one codebase serve both a 2011-era Web Forms app and a .NET 8 sidecar.

Dependencies, all netstandard2.0-safe: `Dapper`, `Newtonsoft.Json` (13.x — **not** `System.Text.Json`,
which drags a large closure onto `net472`), `DbUp`, `Microsoft.Extensions.Logging.Abstractions`,
`Polly` (retry/backoff), `Serilog` sinks in the host projects only.

---

## 6. Data model on SQL Server

MySQL's `JSON` column has no direct SQL Server equivalent before SQL Server 2025. Use
`NVARCHAR(MAX)` + an `ISJSON` check + **persisted computed columns** for the indexed paths — this
mirrors what `SchemaMigrator` already does with generated columns.

```sql
CREATE TABLE df_toplists (
    id                BIGINT IDENTITY(1,1) PRIMARY KEY,
    api_toplist_id    BIGINT       NOT NULL,
    name              NVARCHAR(255) NOT NULL,
    slug              NVARCHAR(255) NULL,
    current_period    NVARCHAR(100) NULL,
    published_at      DATETIME2     NULL,
    item_count        INT           NOT NULL DEFAULT 0,
    locked_count      INT           NOT NULL DEFAULT 0,
    sync_warnings     NVARCHAR(MAX) NULL,
    data              NVARCHAR(MAX) NOT NULL,
    version           NVARCHAR(50)  NULL,
    last_synced       DATETIME2     NOT NULL,
    -- indexed JSON projections (SQL Server 2016+; mirrors ensureToplistsGeoVirtualColumns)
    list_template_id  AS CAST(JSON_VALUE(data, '$.data.template.id')  AS NVARCHAR(50)) PERSISTED,
    geo_type          AS CAST(JSON_VALUE(data, '$.data.geo.geo_type') AS NVARCHAR(50)) PERSISTED,
    geo_code          AS CAST(JSON_VALUE(data, '$.data.geo.code')     AS NVARCHAR(50)) PERSISTED,
    CONSTRAINT ck_df_toplists_json CHECK (ISJSON(data) = 1),
    CONSTRAINT uq_df_toplists_api_id UNIQUE (api_toplist_id)
);
CREATE INDEX ix_df_toplists_slug        ON df_toplists (slug);
CREATE INDEX ix_df_toplists_template    ON df_toplists (list_template_id);
CREATE INDEX ix_df_toplists_geo         ON df_toplists (geo_type, geo_code);
```

`df_brands` mirrors `wp_dataflair_brands` (incl. `review_url_override`, `local_logo_url`,
`cached_review_post_id` → renamed `cached_review_ref NVARCHAR(255)` since the CMS's review identifier
may not be an integer, `is_disabled`, and the persisted `external_id` projection).
`df_alternative_toplists` mirrors its WP counterpart unchanged.

Plus two tables with no WP equivalent (WordPress got these for free from `wp_options`):
`df_settings (name PK, value, updated_at)` and `df_cache (cache_key PK, value, expires_at)` — the
latter backs the campaign-tracker lookup that `/go/` depends on.

Other deltas from MySQL:

- **Upsert:** `MERGE` (or the safer `UPDATE`-then-`INSERT`-if-zero-rows pattern under
  `READ COMMITTED SNAPSHOT`). No `ON DUPLICATE KEY UPDATE`.
- **Paginated delete:** `DELETE TOP (500) FROM …` in a loop — same shape as `PaginatedDeleter`, same
  reason (never lock the whole table).
- **Migrations:** DbUp journal table replaces `dataflair_db_version` + the hand-rolled
  `ALTER TABLE`-if-column-missing ladder. Keep the self-heal behaviour: run pending scripts on boot,
  guarded by a short-lived cache flag so it is not introspected per request.
- **Collation:** their DB may be `SQL_Latin1_General_CP1_CI_AS`; brand slugs must compare
  case-insensitively but store exactly. Force column collation explicitly rather than inheriting.
- **SQL Server ≤ 2014 fallback:** no `JSON_VALUE` — extract `template_id`/`geo_type`/`geo_code` at
  sync time into plain columns. Same queries, one extra write path.

---

## 7. Placement: the shortcode/block problem

WordPress gives content authors two insertion points we do not get for free.

**Shortcode → content token + response filter.** Register an `IHttpModule` that attaches a
`Response.Filter` on `text/html` responses only, scans for
`[dataflair_toplist id="123" limit="10"]`, and replaces it with rendered HTML.

- ✅ Works with *any* CMS, no CMS change, authors type the same token they type in WordPress today.
- ⚠️ Costs: the filter sees every byte of every HTML response; must skip non-HTML by content type;
  must be UTF-8-boundary-safe across chunk boundaries (buffer a small tail between writes); must run
  before IIS dynamic compression (managed modules do — verify on their box); and it is invisible
  magic to a future maintainer.
- **Mitigation:** short-circuit on a cheap `IndexOf("[dataflair_", StringComparison.Ordinal)` before
  any parsing, and let them disable the module entirely and use explicit placement instead.

**Explicit placement (preferred where available):**

```aspx
<%@ Register TagPrefix="df" Namespace="DataFlair.Toplists.Web" Assembly="DataFlair.Toplists.Web" %>
<df:Toplist runat="server" ToplistId="123" Limit="10" Layout="Cards" AutoGeo="true" />
```

```csharp
@Html.DataFlairToplist(123, limit: 10, layout: ToplistLayout.Cards)   // MVC 5
<vc:dataflair-toplist toplist-id="123" limit="10" />                  // ASP.NET Core
```

**Gutenberg block → snippet builder.** No block editor exists, so the authoring UX becomes:
an admin page that lists synced toplists with a live preview, colour/limit/layout controls, and a
**Copy snippet** button producing the exact token. If their editor is TinyMCE or CKEditor, a ~100-line
editor plugin adds a toolbar button that opens that picker inline — that is the closest thing to
block parity and is worth doing once Phase 0 answers question 5.

The 25 block attributes (colours, radii, shadows) become **CSS custom properties** on the wrapper
(`--df-cta-bg`, `--df-ribbon-bg`, …) set from settings or per-token overrides. Cleaner than the WP
version, which threads a `customizations` array through the view model.

---

## 8. Geo + caching — the risk that decides the architecture

The WordPress plugin's approach is: resolve `CF-IPCountry`, gate the render, and set
`DONOTCACHEPAGE` + `nocache_headers()` so the page isn't cached and replayed to another country.
`ToplistShortcode::signalUncacheable()` already admits the limit in its own comment: *it does not
cover edge/CDN caching, because on a cache hit that code never runs.*

On a WordPress affiliate site that is survivable. On cricketworld.com — a high-traffic sports site
sitting behind Cloudflare — marking article pages uncacheable is not acceptable. Four options:

| Option | How | Verdict |
|---|---|---|
| `Cache-Control: private` on pages with a toplist | Same as WP today | ❌ Origin takes full article traffic. Don't. |
| Cloudflare **custom cache key** including `CF-IPCountry` | Cache Rules (Enterprise) or a Worker setting `cf.cacheKey` | ✅ Correct and fast, if their plan allows it. Multiplies cache entries per country — fine, since only a handful of countries matter. |
| **Client-hydrated fragment** (Mode A) | Page HTML is geo-neutral and cacheable; our fragment endpoint varies by country and is cached separately (`Vary`-free, country in the URL) | ✅ **Default recommendation.** No dependency on their Cloudflare plan. |
| Edge injection (Mode C) | Worker + `HTMLRewriter` | ✅ Best UX, needs Workers + their config change. |

Whichever is chosen, the **render logic itself ports unchanged**: `GeoRenderGate` (default-deny,
`global` renders unconditionally, `country` exact match, `market` covered-countries match) and
`GeoFamilySelector` (exact country → single covering market → explicit global, ambiguity = no
render) are pure functions with no WordPress dependency. They are a near-mechanical C# translation
and should keep their existing test cases verbatim.

One addition worth making during the port: the fragment endpoint must include the resolved country in
its **URL path** (`/render/123/GB`), not just a header, so any cache in front of it is correct by
construction rather than by `Vary` configuration.

---

## 9. Sync, jobs, and the IIS hazards

The sync pipeline is the most portable part of the plugin — it is already structured as
`HttpClient → persister → repository` with budgets and retries. Direct translation, with these
platform-specific corrections:

1. **TLS 1.2 must be enabled explicitly.** On .NET Framework < 4.7 the default
   `ServicePointManager.SecurityProtocol` excludes TLS 1.2, so every call to the DataFlair API fails
   with a bare "connection closed". Set it once at startup, or set
   `<AppContextSwitchOverrides value="Switch.System.Net.DontEnableSystemDefaultTlsVersions=false"/>`.
   This will bite on day one if it is not in the setup docs.
2. **One `HttpClient` instance, reused.** `new HttpClient()` per call exhausts sockets under
   `TIME_WAIT`. This is the .NET analogue of the plugin's `PersistentCurlTransport`.
3. **Do not run sync in the web app.** IIS recycles app pools on idle timeout (default 20 min),
   memory limits, and `web.config` writes — mid-sync recycling leaves partial state.
   `HostingEnvironment.QueueBackgroundWorkItem` does not survive it either.
   - **Baseline:** `DataFlair.Toplists.Cli sync --brands --toplists` on Windows Task Scheduler.
   - **Opt-in:** Hangfire with SQL Server storage, plus `startMode="AlwaysRunning"` and Application
     Initialization if they insist on in-process.
4. **Wall-clock budget still matters** — not for PHP's `max_execution_time`, but to keep a scheduled
   run bounded and resumable. `WallClockBudget` translates to a `Stopwatch` + `CancellationToken`
   pair; keep the 3 s headroom check between items.
5. **Logo storage.** Write under a static-only path (e.g. `/content/dataflair/logos/`) with a
   `web.config` in that folder that removes all managed handlers, so an uploaded file can never
   execute. Keep the 3 MB cap, the 8 s timeout, the HEAD-first size check, and the 7-day reuse
   window exactly as `LogoDownloader` has them. Better still on their stack: push logos to a CDN/blob
   container and store the CDN URL in `local_logo_url`.
6. **Concurrency.** Two sync runners must not overlap — take a SQL Server application lock
   (`sp_getapplock`) for the run, which also protects against a Task Scheduler overlap after a slow run.
7. **Delta sync.** Plan `01-dataflair-to-wordpress-webhooks-sync-architecture.md` (Phase A:
   `?modified_since=`) applies identically here, and the .NET client should be written to send that
   parameter from day one if the server already supports it by then — the full-wipe-on-page-1 pattern
   is worse on SQL Server than on MySQL.

---

## 10. Integration contracts (the parts that cannot be ported)

Three things in the plugin are WordPress features, not DataFlair features. Each becomes a small
interface with a sane default and a CMS-specific adapter written during integration.

```csharp
// 1. Reviews. WP resolves override → published `review` CPT permalink → /reviews/{slug}/ → CTA.
//    Their CMS has its own article store; only they can answer "is there a published review".
public interface IReviewUrlResolver
{
    // Return null when no published review exists — the card then hides "Read Review",
    // matching show_read_review_link in casino-card.php.
    ReviewLink Resolve(BrandRef brand);
}
// Default implementation: override column, then pattern /reviews/{slug}/ with an existence probe
// against a configured lookup (sitemap table, CMS API, or a synced slug list).

// 2. Auth. WP uses current_user_can('manage_options').
public interface ICurrentUserContext
{
    bool IsAdministrator { get; }
    string UserName { get; }
}
// Default: ASP.NET role check, configurable role name. Fallback: standalone forms auth +
// IP allowlist. Never ship an unauthenticated admin surface.

// 3. Site URLs. WP uses home_url().
public interface ISiteUrlProvider
{
    string Absolute(string relativePath);   // "/go/?campaign=x" -> "https://www.cricketworld.com/go/?campaign=x"
}
```

A fourth, optional: `IContentTokenSource` — if their CMS can hand us article bodies at render time we
can skip the response filter entirely and rewrite tokens in the content pipeline instead.

---

## 11. Frontend assets

- **`assets/style.css` (3.9 KB) ports as-is**, with one required change: prefix every selector
  (`.casino-card` → `.df-casino-card`) so nothing collides with Bootstrap on their site.
- **Bug to fix during the port, not copy:** `views/frontend/casino-card.php` lines 485–506 emit
  Tailwind utility classes (`grid grid-cols-1 tablet:grid-cols-2 gap-4`, `text-green-700`,
  `list-disc list-inside`) for the pros/cons block. Those only render correctly on a site that
  happens to load Tailwind. On cricketworld.com they will render as an unstyled list. Replace with
  plugin-owned CSS. *(Worth fixing on the WordPress side too — it is latent there for any non-Tailwind
  theme.)*
- **Alpine.js → ~40 lines of vanilla JS.** The template uses `x-data`, `x-show`, `@click`,
  `x-transition` for the "Show more" details panel. Their site has no Alpine; adding it for one toggle
  is not justified. A `<details>` element or a tiny delegated click handler is enough, and it keeps
  the card working with JS disabled.
- **Promo-code copy** already ships as a standalone delegated listener with a `data-promoBound`
  guard — port as-is (`navigator.clipboard` with a `document.execCommand` fallback for old browsers).
- **Images:** logos get `loading="lazy"` + `decoding="async"`, or their Lozad convention. Always set
  explicit `width`/`height` to avoid CLS in an article body.
- **CSP:** if they run a Content-Security-Policy, our inline `onerror="…"` logo fallback (line 303 of
  the template) will be blocked. Move it to the delegated script.
- **Affiliate link rel:** the template uses `rel="nofollow"`. For a UK publisher, `rel="sponsored
  nofollow noopener"` is the current-guidance value. Make it configurable, default to the safer set.
- **GTM:** push a `dataflair_cta_click` event with brand, position, toplist ID, and campaign — they
  will ask for it, and it is trivial to add at the same delegated listener.

---

## 12. Testing, perf, and the invariants worth keeping

The plugin earned its current invariants the hard way (a production OOM). Carry them over as
*executable* rules, not prose:

| Invariant | How to enforce in .NET |
|---|---|
| **Render is read-only** — no HTTP, no file writes, no DB writes | `NetArchTest`: types in `DataFlair.Toplists.Rendering` may not depend on `HttpClient`, `System.IO.File`, or any `*Repository.Save*`. Fails the build, same as `RenderIsReadOnlyTest`. |
| **Brand meta is prefetched, not per-card** | Unit test asserting a fixed query count for an N-item toplist (Dapper interceptor counter). |
| **Geo default-deny** | Port the existing `GeoRenderGate` / `GeoFamilySelector` test cases 1:1 — they are pure logic and should pass unchanged. |
| **Perf gate** | BenchmarkDotNet + a CI job on the Sigma-equivalent fixture: 200 toplists × 20 items, 500 brands. Targets for .NET: **≤5 s** wall (same as PHP) is far too generous; set **≤150 ms per render** and **≤200 MB** working set, and treat a regression past 2× as a gate failure. |
| **Response filter cost** | Benchmark the module against a 200 KB HTML page with no token present — must be < 1 ms. |

Test data: reuse `tests/phpunit/fixtures/api-toplist-*.json` verbatim. Same fixtures across both
implementations is the cheapest possible parity check, and it catches divergence in the field-name
fallback chains (`type` / `productType` / `product_type`, the 7 logo keys, `items` / `listItems`)
that make up a surprising share of this codebase.

---

## 13. Delivery phases

| Phase | Scope | Rough size | Done when |
|---|---|---|---|
| **P0** | Discovery (§4) | 2 days (their input) | All 10 questions answered; Mode A vs B decided. |
| **P1** | Core + Data + Http + Sync, `netstandard2.0`, no UI | 2 weeks | `DataFlair.Toplists.Cli sync` populates SQL Server from the live API; fixtures round-trip; delta-sync-ready. |
| **P2** | Rendering (cards + table), geo, review resolver, `/go/` redirect | 2 weeks | Given a toplist ID and a country, the library returns byte-sane HTML. Golden-file tests vs the PHP output. |
| **P3** | **Mode A sidecar** + `df.js` embed + one live page on cricketworld staging | 1 week | A real toplist renders on a real page, geo-correct, edge-cacheable. **← first commercial value** |
| **P4** | Admin UI: settings, toplists list, brands, sync console, health | 3 weeks | An operator can configure the token, run a sync, set a review-URL override, and see failures without SSH. |
| **P5** | **Mode B** in-process: module, control/helper, response filter, snippet builder | 2 weeks | Token in an article body renders server-side on their CMS. |
| **P6** | Ops hardening: scheduled runner, logging/Sentry, perf gate in CI, NuGet packaging + update tile, docs | 2 weeks | Deployable by their team without us in the room. |

≈ **12 weeks to full parity** for one experienced .NET developer; **≈5 weeks to the first live,
revenue-earning toplist** (P0–P3). Phases 4–6 can be reordered against commercial pressure; P1–P3
cannot.

---

## 14. Risks

| Risk | Impact | Mitigation |
|---|---|---|
| **Edge caching serves one country's toplist to another** | Compliance exposure, not just a bug | Mode A by default (§8); country in the fragment URL; never rely on `Vary` alone. |
| CMS is Web Forms with no DI, no routing, no build pipeline | Mode B slips badly | Mode A has zero dependency on any of that — it is the hedge. |
| No queryable "is there a published review" source | "Read Review" link either never shows or 404s | `IReviewUrlResolver` + an explicit override column; ship with the link hidden until a real source exists (matches current default-deny behaviour). |
| .NET Framework 4.5 or lower | `netstandard2.0` will not load | Detect in P0; multi-target `net45` with older dependency versions if needed. |
| SQL Server < 2016 | No JSON functions | Sync-time extracted columns (the plugin already does this for `item_count`); only the geo-family query loses its index. |
| App-pool recycling mid-sync | Half-synced state | Out-of-process runner + `sp_getapplock` + resumable page-based batching (already the design). |
| Two implementations drift | Bug fixed in PHP, missed in C# | Shared JSON fixtures + a shared `docs/contracts/` spec; treat the fixtures as the contract, not either codebase. |
| Gambling content on a mainstream sports site | Editorial/compliance escalation | Per-market disclaimer text, geo default-deny, and a site-level kill switch (already exists as `dataflair_geo_targeting_enabled`). |

---

## 15. Non-goals

- Porting the WordPress **admin theme**. The admin is rebuilt to fit their site, not restyled to look
  like `wp-admin`.
- Porting the **Gutenberg block** as a block. It becomes a snippet builder + optional editor button.
- A **shared runtime** between PHP and .NET. Two implementations, one API contract, one set of
  fixtures. Any attempt to share code across the two is a trap.
- Replacing their CMS, their editor, or their deployment process.

---

## Open questions

1. Does DataFlair want this as a **product** (sold to any ASP.NET publisher) or a **one-off
   integration** for cricketworld? That decides how much goes into the admin UI vs a config file.
2. Mode A's sidecar needs a host — DataFlair's infra, or theirs? (Affects latency, cost, and who is
   on call when it is down.)
3. Should the .NET client target the v2 API from the start, skipping v1 entirely? The plugin still
   defaults brands to v1 (`dataflair_brands_api_version`); a greenfield client has no legacy to carry.
4. Is `/go/?campaign=…` acceptable on their domain, or do they want affiliate redirects on a
   subdomain? (Cloudflare rules and their SEO team will have an opinion.)
5. Do we commit to golden-file HTML parity with the PHP renderer, or is "same data, site-native
   markup" the goal? Parity is easier to verify; site-native looks better on their pages.
