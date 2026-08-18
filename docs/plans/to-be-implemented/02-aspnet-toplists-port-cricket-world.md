# DataFlair Toplists for ASP.NET — Port Plan (Cricket World)

> **Status:** to be implemented · **Created:** 2026-08-18 · **Owner:** Mex
> **Target host:** cricketworld.com — custom CMS on Microsoft ASP.NET (CLR 4.0.30319 → .NET Framework 4.x), fronted by Cloudflare
> **Source of truth:** this repo at v2.2.11 (~18k LOC PHP, 112 PHPUnit tests)
> **Deliverable:** `DataFlair.Toplists` — a drop-in .NET class library that gives a non-WordPress ASP.NET site the same toplist capability this plugin gives WordPress.

---

## 1. Why this plan exists

Cricket World is not WordPress. It runs a bespoke CMS on .NET Framework 4.x. There is no
`wp-content/plugins`, no `add_shortcode`, no `wp_options`, no `$wpdb`, no Gutenberg. Every
integration seam this plugin relies on has to be re-created from primitives.

The good news: this plugin is already structured for the port. Since Phase 8/9 it is a
DI-wired set of single-responsibility classes behind interfaces (`HttpClientInterface`,
`ToplistsRepositoryInterface`, `CardRendererInterface`, `VisitorGeoResolverInterface`, …),
and the WordPress-specific parts are concentrated at the edges. The domain core —
sync pipeline, geo gate, review-URL cascade, card view-model, redirect logic — is
portable almost 1:1.

This document is the porting contract: what exists, what it becomes in .NET, what
cannot come across, and in what order to build it.

---

## 2. What we know about the target — and what we still must confirm

### Confirmed from the stack fingerprint

| Signal | What it means for us |
|---|---|
| `Microsoft ASP.NET 4.0.30319` | CLR 4 → .NET Framework **4.0–4.8**. Not .NET Core/5+. Target `net48`, fall back to `net472` if needed. |
| **Cloudflare** in front | `CF-IPCountry` is available for free — the exact header `VisitorGeoResolver` already prefers. Geo-targeting ports with zero new dependencies. **Also the single biggest risk** — see §11. |
| Clean URLs (`/about-us/`, no `.aspx`) | URL routing or rewriting is already in play. A `/go/` affiliate route can be registered without fighting the host. |
| **jQuery 3.7 + Bootstrap** | Admin UI can reuse the existing jQuery-based admin JS with modest rework. |
| **Preact + Goober** already loaded | If we ever need a client-side island (geo hydration — §11), the runtime is already on the page. |
| **Lozad.js** (lazy loading) | Brand logos should emit `data-src` + `lozad` class to match house conventions, not eager `src`. |
| Google Tag Manager | CTA/impression tracking should push to `dataLayer`, not invent its own beacon. |

### Must be answered before Phase 1 starts

These are blocking design inputs, not nice-to-haves. Ask the Cricket World engineering owner:

1. **WebForms, MVC5, or both?** Determines whether the embed surface is a `<df:Toplist>` server control, an `@Html.DataFlairToplist()` helper, or both. *Plan assumes both are needed until told otherwise.*
2. **Database engine and access.** SQL Server is assumed. Can we create three tables in the CMS database, or do we get a separate database/schema? Do we get DDL rights at deploy time, or must migrations be handed to a DBA as scripts?
3. **How does article body content get rendered?** Is there a single code path where the stored HTML of an article is emitted? A one-line hook there is worth ten times a `Response.Filter` (§9.2).
4. **What is the editor?** CKEditor / TinyMCE / a bespoke field UI? Determines whether editors get a real "Insert Toplist" button or a copy-paste token.
5. **Deploy model.** Can we drop a DLL into `/bin` and add a `web.config` block, or is everything built from one solution in CI? Determines whether we ship a NuGet package or a source-integrated project.
6. **Admin authentication.** Is there an existing admin area with an auth mechanism we can require, or do we need our own gate?
7. **Cloudflare plan tier.** Enterprise unlocks cache-key-by-country, which makes geo-targeting trivial. Without it, §11's decision tree applies.
8. **Is there an existing brand-review content type?** The WP plugin leans hard on the `review` CPT (§10.1). If Cricket World has review articles, we map to them; if not, the Read Review feature degrades to the override field only.

---

## 3. What "a plugin" means on ASP.NET

There is no WordPress-style plugin API in .NET Framework. There are, however, well-worn
mechanisms that add up to the same thing — code you drop in that self-registers and
extends the host without the host knowing about it:

| WordPress concept | ASP.NET Framework equivalent |
|---|---|
| Plugin folder scanned at boot | A **class library DLL** in `/bin`, discovered by the runtime |
| `register_activation_hook` | `[assembly: PreApplicationStartMethod]` or **WebActivatorEx** — runs our startup code with **no edit to the host's `Global.asax`** |
| `add_action` / `add_filter` | `IHttpModule` events (`BeginRequest`, `PreRequestHandlerExecute`, …) |
| `add_shortcode` | Output token expansion (`Response.Filter` or a CMS content hook) + a Razor helper + a WebForms control |
| Admin menu pages | An `IHttpHandler` / MVC **Area** mounted at a reserved path |
| `wp-content/uploads` | `~/App_Data/dataflair/` or a configured static path |
| Plugin settings screen | Our own settings table + admin screen (there is no `wp_options`) |

**That combination — `PreApplicationStartMethod` + `IHttpModule` + routed handlers +
embedded assets — is as close to "a WordPress plugin" as .NET Framework gets.** One DLL,
one `web.config` block, no host source changes.

### Three delivery models considered

| | **A. In-process .NET library** | **B. JS embed / iframe widget** | **C. Hybrid: SSR library + optional JS island** |
|---|---|---|---|
| Host code changes | DLL + `web.config` block | one `<script>` tag | DLL + `web.config` block |
| SEO | Full server-rendered HTML ✅ | Content invisible to crawlers ❌ | Full SSR ✅ |
| Page speed | No extra round-trip ✅ | Extra RTT + CLS ⚠️ | SSR default, island only where needed ✅ |
| Geo targeting | Server-side, `CF-IPCountry` ✅ | Client-side, easy ✅ | Both available ✅ |
| Effort | High | Low | High |
| CDN cache interaction | Needs care (§11) | Trivial | Solved per-toplist (§11) |
| Ownership | Cricket World deploys it | We host it | Cricket World deploys it |

**Recommendation: C.** SSR is non-negotiable — toplists are the commercial payload of
affiliate pages, and a client-rendered comparison table forfeits the rankings the pages
exist to win. The optional JS island exists solely to solve the Cloudflare-cache-vs-geo
problem on the minority of pages that use country/market-scoped toplists.

Option B is worth keeping in the back pocket as a **Phase 0 pilot**: it can prove the data
pipeline end-to-end on a single page in days rather than weeks, while the real library is
being built. It should never become the permanent answer.

---

## 4. Feature inventory — the porting checklist

Everything the WordPress plugin does today, and its disposition in the port.

### 4.1 Sync (from the DataFlair API)

| Behaviour | Port |
|---|---|
| `GET {base}/toplists?per_page&page` — paginated list, full items+brand+offer+tracker payload | ✅ 1:1 |
| `GET {base}/toplists/{id}` — per-ID fallback | ✅ 1:1 |
| `GET {base}/brands?per_page&page` — v1 default, v2 opt-in | ✅ 1:1 |
| Bearer token auth, `Accept: application/json` | ✅ 1:1 |
| Base URL resolution: setting → cached endpoint → `https://sigma.dataflair.ai/api/v1` | ✅ 1:1 |
| 15 MB response cap | ✅ 1:1 (`HttpClient.MaxResponseContentBufferSize`) |
| 12 s default / 20 s list timeout | ✅ 1:1 |
| Exponential retry on 500/502/503/504 + connection errors, max 2 | ✅ 1:1 (Polly, or hand-rolled) |
| Progressive split fallback on bulk 5xx (per_page 25 → 5 → 1) | ✅ 1:1 |
| `WallClockBudget` cooperative cancellation (25 s budget, 3 s headroom) | ✅ becomes `CancellationToken` + deadline |
| Paginated DELETE on page 1 (never `TRUNCATE`) | ✅ 1:1 |
| Persistent cURL transport | ➖ dropped — a static `HttpClient` already pools connections |
| HTTP Basic auth passthrough for staging | ✅ 1:1 |
| Docker `.test`/`.local` host rewrite | ➖ dropped — dev-environment-specific |
| Logo download + local caching | ✅ 1:1, path changes (§8.6) |
| Sync history ring buffer + alert email | ✅ 1:1 |

### 4.2 Storage

| Behaviour | Port |
|---|---|
| 3 tables: toplists, brands, alternative toplists | ✅ 1:1 (SQL Server, §7) |
| JSON blob column with typed projections alongside | ✅ `NVARCHAR(MAX)` + `ISJSON` check constraint |
| Versioned incremental schema migrations (currently v1.13) | ✅ 1:1, mechanism changes (§8.2) |
| Self-heal: recreate missing tables on boot, short-circuited by a 12 h cache flag | ✅ 1:1 |
| Generated/virtual geo columns for indexed geo lookup | ✅ SQL Server **computed columns**, persisted + indexed |
| `wp_options` for ~20 settings | ➡️ new `DataFlairSettings` table (§7) |
| Transients (tracker campaign map, schema-ok flag, sync locks) | ⚠️ **must not** become `MemoryCache` alone — §10.3 |

### 4.3 Rendering

| Behaviour | Port |
|---|---|
| Casino card: ribbon (#1), position badge, logo + initials fallback, brand link, star rating, Read Review link, bonus label/text, promo-code copy button, 3 feature bullets, CTA button | ✅ 1:1, markup preserved |
| Expandable details panel (Alpine.js `x-data="{ showDetails: false }"`) — metrics grid, pros/cons, licences, payment methods | ✅ 1:1 (keep Alpine; §8.7) |
| Table layout + accordion table layout | ✅ 1:1 |
| Product-type labels (casino / sportsbook / poker) | ✅ 1:1 |
| Review-URL cascade: `review_url_override` → published review permalink → `/reviews/{slug}/` → affiliate CTA | ⚠️ middle step needs a host-specific resolver — §10.1 |
| Stale-data banner after 3 days | ✅ 1:1 |
| **Render-time read-only guarantee**: no HTTP, no media sideload, no content writes | ✅ **carry across as a pinned test** — this is the most valuable invariant in the codebase |
| H7 batched brand-meta prefetch (flat query count regardless of item count) | ✅ 1:1 |
| Pre-computed `local_logo_url` + `cached_review_post_id` at sync time | ✅ 1:1 |
| Per-block colour customisations | ✅ 1:1 via token attributes + settings defaults |
| Pros/cons overrides keyed by stable brand/item ID | ✅ 1:1 |

### 4.4 Placement, redirect, geo

| Behaviour | Port |
|---|---|
| `[dataflair_toplist id\|slug limit layout ctaMode template auto_geo]` shortcode | ✅ token expander (§9.2) |
| Gutenberg block (server-rendered wrapper over the same renderer) | ➡️ replaced by an editor integration (§10.4) |
| `/go/?campaign=NAME` → 301 to tracker link, 404 on miss/invalid | ✅ 1:1 (§9.3) |
| Tracker map persisted for 30 days keyed by `md5(campaign)` | ⚠️ must become a DB table — §10.3 |
| Geo gate, default-deny: `global` always renders; `country` matches `code`; `market` matches `coveredCountries`; unresolved country renders nothing | ✅ 1:1 — algorithm copied verbatim |
| Geo family auto-select by template ID | ✅ 1:1 |
| `?dataflair_geo=GB` admin-only QA override | ✅ 1:1, gated on the host's admin auth |
| `DONOTCACHEPAGE` + `nocache_headers()` on every render | ⚠️ **the crux of §11** |

### 4.5 Admin, API, ops

| Behaviour | Port |
|---|---|
| 5 admin screens: Dashboard, Toplists, Brands, Tools, Settings | ✅ rebuilt (§8.8) |
| 23 AJAX endpoints (sync batches, bulk ops, brand details, logs, tests, API preview, settings save) | ✅ rebuilt as JSON endpoints |
| Nonce + `manage_options` on every admin route | ➡️ AntiForgeryToken + host admin auth (§12) |
| REST: `/toplists`, `/toplists/{id}/casinos`, `/health` | ✅ 1:1 under `/dataflair/api/v1/` |
| Structured logging (file / error_log / Sentry) | ✅ 1:1 (`ILogger` abstraction, same three sinks) |
| Data-integrity checker | ✅ 1:1 |
| WP-CLI `reconcile-reviews` | ➡️ admin button + a console tool |
| GitHub-release auto-update | ❌ not portable — §10.2 |

---

## 5. Target architecture

```
DataFlair.Toplists.sln
├── DataFlair.Toplists.Core            (netstandard2.0)   ← no ASP.NET, no SQL
│   ├── Models/                        Toplist, Brand, Offer, Tracker, Geo, ProductType
│   ├── Sync/                          ToplistSyncService, BrandSyncService, SyncRequest/Result,
│   │                                  EndpointDiscovery, WallClockBudget → DeadlineToken
│   ├── Http/                          IDataFlairApiClient, ApiClient, BaseUrlDetector, UrlBuilders
│   ├── Geo/                           GeoRenderGate, GeoFamilySelector, IVisitorGeoResolver
│   ├── Rendering/                     CasinoCardViewModel, ToplistTableViewModel,
│   │                                  ProsConsResolver, ReviewUrlResolver, SyncLabelFormatter
│   ├── Abstractions/                  IToplistRepository, IBrandRepository, ISettingsStore,
│   │                                  IKeyValueStore, ILogoStore, IClock, ILogger
│   └── Diagnostics/                   DataIntegrityChecker
│
├── DataFlair.Toplists.Data.SqlServer  (netstandard2.0)   ← Dapper + embedded migrations
│   ├── Migrations/                    001_initial.sql … 013_geo_computed_columns.sql
│   ├── SchemaMigrator.cs              mirrors src/Database/SchemaMigrator.php
│   └── Repositories/                  Toplist, Brand, Alternatives, Settings, KeyValue
│
├── DataFlair.Toplists.Web             (net48)            ← the "plugin" surface
│   ├── Startup.cs                     [PreApplicationStartMethod] — self-registration
│   ├── Modules/DataFlairModule.cs     IHttpModule: token expansion, geo header capture
│   ├── Routes/GoRouteHandler.cs       /go/?campaign=…
│   ├── Handlers/AssetHandler.cs       embedded CSS/JS at /dataflair/assets/*
│   ├── Api/                           /dataflair/api/v1/{toplists,casinos,health}
│   ├── Admin/                         /dataflair/admin/* — 5 screens + JSON endpoints
│   ├── Rendering/                     precompiled Razor templates (RazorGenerator)
│   ├── Mvc/HtmlHelperExtensions.cs    @Html.DataFlairToplist(…)
│   ├── WebForms/ToplistControl.cs     <df:Toplist ID="…" runat="server" />
│   └── EmbeddedResources/             style.css, editor.css, promo-copy.js
│
└── DataFlair.Toplists.Tests           (net48, xUnit)     ← port of the 112 PHPUnit tests
```

### Why these choices

- **`netstandard2.0` for Core/Data.** Runs on .NET Framework 4.7.2+ *and* on .NET 8. If
  Cricket World ever migrates off .NET Framework, 80% of this port migrates for free.
  Only `.Web` is framework-bound.
- **Dapper, not Entity Framework.** The data model is three tables with JSON blobs. EF's
  change tracker and migration story buy nothing here and cost startup time; the existing
  repositories are already hand-written SQL that translates directly.
- **Embedded `.sql` migrations + a version row**, mirroring `SchemaMigrator.php` — same
  semantics (idempotent, self-healing, version-gated), no new dependency, and the scripts
  can be handed to a DBA if we don't get DDL rights.
- **Precompiled Razor via RazorGenerator**, not runtime views. Templates compile into the
  DLL, so there are no `.cshtml` files to deploy, no view-engine registration, no MVC
  dependency — the same renderer works from WebForms, MVC, and the API. Razor also
  HTML-encodes by default, which is how we keep the `esc_html`/`esc_attr` discipline.
- **Static `HttpClient`.** Replaces both `wp_remote_get` and the persistent-cURL
  optimisation in one move.
- **Embedded static assets** served by a handler with immutable cache headers and a
  version-stamped URL. One DLL, nothing loose on disk.

---

## 6. WordPress primitive → .NET equivalent

| WordPress | .NET Framework | Notes |
|---|---|---|
| `$wpdb->prepare()` / `get_results()` | Dapper + parameterised SQL | Parameterisation is mandatory, same as today |
| `wp_options` | `DataFlairSettings` table behind `ISettingsStore` | Cached in `MemoryCache`, invalidated on write |
| `get_transient` / `set_transient` | `IKeyValueStore` → `MemoryCache` **+ DB fallback table** | §10.3 — durability matters for the tracker map |
| `wp_remote_get` | `HttpClient` (static, pooled) | |
| `WP_Error` | `Result<T>` / typed exceptions | Keep the "no exceptions across the sync boundary" style |
| WP-Cron | External scheduled trigger → `POST /dataflair/sync/run` | **The plugin already removed WP-Cron in v1.11.0** — this is a natural fit, not a compromise |
| AJAX batch sync loop | Same loop, driven by the admin JS against .NET endpoints | Batching stays: it is what keeps sync inside request limits |
| `add_shortcode` | Token expander + Razor helper + WebForms control | §9.2 |
| Gutenberg block | Editor "Insert toplist" integration | §10.4 |
| `esc_html` / `esc_attr` / `esc_url` | Razor auto-encode; `HttpUtility.HtmlAttributeEncode`; an explicit URL scheme allowlist | Do **not** rely on Razor alone for `href` — keep the URL validator |
| `wp_nonce_field` / `check_ajax_referer` | `@Html.AntiForgeryToken()` / `ValidateAntiForgeryToken` | |
| `current_user_can('manage_options')` | Host admin auth (§12) | |
| `register_activation_hook` | Migration runner on first request via `PreApplicationStartMethod` | |
| `plugins_loaded` self-heal | Same check, same 12 h short-circuit flag | |
| `home_url()` | Absolute URL builder from `HttpContext.Request` + a configurable canonical host | Must respect Cloudflare's `X-Forwarded-Proto` |
| `/wp-content/uploads/logos/` | `~/App_Data/dataflair/logos/` + a public serving route | §8.6 |
| `review` CPT + `_review_pros` meta | **No equivalent** | §10.1 — the largest delta |
| plugin-update-checker | NuGet version bump + deploy | §10.2 |
| `DONOTCACHEPAGE` | `Response.Cache.SetCacheability(Private)` + Cloudflare bypass rule | §11 |
| `CF-IPCountry` | `CF-IPCountry` — identical ✅ | Confirm Cloudflare IP Geolocation is enabled |

---

## 7. Data model (SQL Server)

Names use `dbo.DataFlair*` rather than a `wp_` prefix. Column names keep the plugin's
snake_case so the JSON payload mapping and the existing repository SQL stay recognisable.

```sql
CREATE TABLE dbo.DataFlairToplists (
    id                BIGINT IDENTITY(1,1) PRIMARY KEY,
    api_toplist_id    BIGINT        NOT NULL,
    name              NVARCHAR(255) NOT NULL,
    slug              NVARCHAR(255) NULL,
    current_period    NVARCHAR(100) NULL,
    published_at      DATETIME2     NULL,
    item_count        INT           NOT NULL DEFAULT 0,
    locked_count      INT           NOT NULL DEFAULT 0,
    sync_warnings     NVARCHAR(MAX) NULL,
    data              NVARCHAR(MAX) NOT NULL,          -- CHECK (ISJSON(data) = 1)
    version           NVARCHAR(50)  NULL,
    last_synced       DATETIME2     NOT NULL,
    -- indexed geo projections (replaces the MySQL generated columns)
    geo_type   AS JSON_VALUE(data, '$.data.geo.geo_type') PERSISTED,
    geo_code   AS JSON_VALUE(data, '$.data.geo.code')     PERSISTED,
    template_id AS TRY_CAST(JSON_VALUE(data, '$.data.template.id') AS BIGINT) PERSISTED,
    CONSTRAINT UQ_DataFlairToplists_ApiId UNIQUE (api_toplist_id)
);
CREATE INDEX IX_DataFlairToplists_Geo      ON dbo.DataFlairToplists (geo_type, geo_code);
CREATE INDEX IX_DataFlairToplists_Template ON dbo.DataFlairToplists (template_id);
CREATE INDEX IX_DataFlairToplists_Slug     ON dbo.DataFlairToplists (slug);

CREATE TABLE dbo.DataFlairBrands (
    id                     BIGINT IDENTITY(1,1) PRIMARY KEY,
    api_brand_id           BIGINT        NOT NULL,
    name                   NVARCHAR(255) NOT NULL,
    slug                   NVARCHAR(255) NOT NULL,
    status                 NVARCHAR(50)  NOT NULL,
    product_types          NVARCHAR(MAX) NULL,
    licenses               NVARCHAR(MAX) NULL,
    top_geos               NVARCHAR(MAX) NULL,
    offers_count           INT           NOT NULL DEFAULT 0,
    trackers_count         INT           NOT NULL DEFAULT 0,
    classification_types   NVARCHAR(500) NOT NULL DEFAULT '',
    review_url_override    NVARCHAR(500) NULL,
    local_logo_url         NVARCHAR(500) NULL,   -- pre-computed at sync (read-only render)
    cached_review_url      NVARCHAR(500) NULL,   -- replaces cached_review_post_id (§10.1)
    editorial_pros         NVARCHAR(MAX) NULL,   -- replaces the _review_pros post meta
    editorial_cons         NVARCHAR(MAX) NULL,
    is_disabled            BIT           NOT NULL DEFAULT 0,
    data                   NVARCHAR(MAX) NOT NULL,
    last_synced            DATETIME2     NOT NULL,
    CONSTRAINT UQ_DataFlairBrands_ApiId UNIQUE (api_brand_id)
);
CREATE INDEX IX_DataFlairBrands_Slug     ON dbo.DataFlairBrands (slug);
CREATE INDEX IX_DataFlairBrands_Disabled ON dbo.DataFlairBrands (is_disabled);

CREATE TABLE dbo.DataFlairAlternativeToplists (
    id                     BIGINT IDENTITY(1,1) PRIMARY KEY,
    toplist_id             BIGINT       NOT NULL,
    geo                    NVARCHAR(255) NOT NULL,
    alternative_toplist_id BIGINT       NOT NULL,
    created_at             DATETIME2    NOT NULL,
    updated_at             DATETIME2    NOT NULL,
    CONSTRAINT UQ_DataFlairAlt_ToplistGeo UNIQUE (toplist_id, geo)
);

-- New: replaces wp_options
CREATE TABLE dbo.DataFlairSettings (
    [key]      NVARCHAR(128) PRIMARY KEY,
    value      NVARCHAR(MAX) NULL,
    updated_at DATETIME2     NOT NULL
);

-- New: replaces durable transients (tracker map, sync locks, schema-ok flag)
CREATE TABLE dbo.DataFlairCache (
    [key]      NVARCHAR(191) PRIMARY KEY,
    value      NVARCHAR(MAX) NULL,
    expires_at DATETIME2     NOT NULL
);
CREATE INDEX IX_DataFlairCache_Expiry ON dbo.DataFlairCache (expires_at);

-- New: sync history (was a serialised option ring buffer)
CREATE TABLE dbo.DataFlairSyncHistory (
    id          BIGINT IDENTITY(1,1) PRIMARY KEY,
    sync_type   NVARCHAR(32)  NOT NULL,
    started_at  DATETIME2     NOT NULL,
    finished_at DATETIME2     NULL,
    succeeded   BIT           NOT NULL DEFAULT 0,
    item_count  INT           NOT NULL DEFAULT 0,
    message     NVARCHAR(MAX) NULL
);
```

**Migration parity:** every `ALTER TABLE … ADD COLUMN` step in `SchemaMigrator.php`
(v1.2 → v1.13) becomes a numbered `.sql` file. The runner records the applied version in
`DataFlairSettings['db_version']`, short-circuits on a 12 h `DataFlairCache` flag, and
self-heals missing tables exactly as the PHP does.

---

## 8. Component design

### 8.1 Startup and self-registration

```csharp
[assembly: PreApplicationStartMethod(typeof(DataFlair.Toplists.Web.Startup), "Start")]

public static class Startup
{
    public static void Start()
    {
        DynamicModuleUtility.RegisterModule(typeof(DataFlairModule));   // token expansion
        RouteTable.Routes.Add("dataflair-go",    new Route("go",  new GoRouteHandler()));
        RouteTable.Routes.Add("dataflair-admin", new Route("dataflair/{*path}", new AdminRouteHandler()));
        HostingEnvironment.QueueBackgroundWorkItem(_ => SchemaMigrator.EnsureUpToDate());
    }
}
```

`web.config` addition is then a single `<add>` for the assembly plus config keys — no
`Global.asax` edit, which is what makes this feel like a plugin rather than a fork.

### 8.2 Schema migrator

Direct port of `src/Database/SchemaMigrator.php`. Same three responsibilities: create
tables, apply numbered upgrades, self-heal. Same 12-hour warm-path short-circuit. Runs on
first request after deploy. If DDL rights are withheld, the same embedded scripts are
exported for a DBA and the runner switches to verify-only mode (log loudly, refuse to
sync into a schema it cannot vouch for).

### 8.3 Sync engine

The pipeline is a straight port; only three things change:

- `WallClockBudget` → `CancellationTokenSource` with a deadline. Same 25 s budget, same
  3 s headroom check between items.
- Batch driving moves from `wp_ajax_dataflair_sync_toplists_batch` to
  `POST /dataflair/admin/sync/toplists { page, perPage }`, called in a loop by the same
  style of admin JS **and** by the scheduled trigger.
- Scheduling: an external caller (Windows Task Scheduler, a Cloudflare Worker cron, or
  Hangfire if they already run it) hits `POST /dataflair/sync/run` with a bearer token
  from config. This is *more* reliable than WP-Cron, and the plugin already abandoned
  WP-Cron, so there is no behavioural regression.

Retry, response caps, progressive split, page-1 paginated delete, per-ID fallback, and the
telemetry hook payloads all port unchanged.

### 8.4 Repositories

One class per table, Dapper-backed, behind the interfaces `Core` already defines. The
H7 batched brand-meta prefetch (`BrandMetaPrefetcher`) ports directly — one
`WHERE api_brand_id IN (@ids)` round-trip per render, regardless of item count. Keep the
test that pins query count.

### 8.5 Renderer

Precompiled Razor templates, one per layout (`CasinoCard`, `ToplistTable`,
`ToplistAccordion`), fed by the existing view-models. Markup is copied from
`views/frontend/casino-card.php` and the table renderer **verbatim** — same class names,
same DOM, same SVGs. This matters: the CSS in `assets/style.css` and `assets/editor.css`
then ports unchanged, and any visual regression is a real bug rather than a redesign.

The read-only invariant is carried across as a test in the same spirit as
`RenderIsReadOnlyTest`: the render path may not touch `HttpClient`, may not open a write
connection, may not call the logo store. Enforce with a test-time guard that fails on any
such call.

### 8.6 Logos

Sync-time download to `~/App_Data/dataflair/logos/{slug}.{ext}`, with the public URL
pre-computed into `local_logo_url` (unchanged design). Serving options, in order of
preference:

1. A configured static path under the web root (fastest, Cloudflare caches it).
2. Failing that, the asset handler streams from `App_Data` with immutable cache headers.

Emit `class="lozad" data-src="…"` to match the site's existing Lozad lazy-loading, with a
`<noscript>` fallback so crawlers still see the image.

### 8.7 Front-end assets

- `style.css` + `editor.css` (12 KB combined, plain CSS, no build step) ship as embedded
  resources, served concatenated and minified at
  `/dataflair/assets/toplists.css?v={assemblyVersion}` with a one-year immutable header.
- The promo-code copy script ports as-is, still guarded by the `data-promoBound` flag so
  repeated blocks don't double-bind.
- **Alpine.js**: the card's expandable details use `x-data`. Keep Alpine (17 KB from
  jsDelivr, matching the current pin at 3.13.5) behind the same
  "is it already on the page?" detection. Given Preact is already loaded site-wide, a
  Preact-based details toggle is a reasonable Phase 4 optimisation — but not a Phase 1
  concern, and not worth diverging the markup for.

### 8.8 Admin

Five screens at `/dataflair/admin/*`, rebuilt in Razor + Bootstrap (already on the site)
+ jQuery (already on the site):

- **Dashboard** — API health, brands/toplists counts, last + next sync, recent activity, one-click sync buttons.
- **Toplists** — search, sort, bulk re-sync, bulk delete, per-row accordion (items + raw JSON).
- **Brands** — full table with inline `review_url_override` editing and the new `editorial_pros`/`editorial_cons` fields (§10.1).
- **Tools** — integrity tests runner, log tail + download, API preview.
- **Settings** — API token, base URL, colours, sync cadence/retries/alert email, geo toggle.

The existing `assets/admin/*.js` (≈50 KB of jQuery) is largely reusable: swap the
`admin-ajax.php?action=dataflair_*` URLs for `/dataflair/admin/api/*`, and the nonce field
for an anti-forgery token. That is a genuine saving — the sync console, dirty-state guard,
colour pickers, and brands table all come across.

### 8.9 Public API

`/dataflair/api/v1/toplists`, `/dataflair/api/v1/toplists/{id}/casinos`,
`/dataflair/api/v1/health` — same shapes as the REST controllers, so anything already
built against the WordPress endpoints keeps working. These also back the optional client
hydration island in §11.

---

## 9. Getting a toplist onto a page

This is the part with no clean .NET equivalent, and the part most likely to be
underestimated. Three mechanisms, all worth shipping:

### 9.1 Developer placement — template helpers

```razor
@Html.DataFlairToplist(new ToplistOptions { Id = 123, Limit = 10, Layout = "cards" })
```
```aspx
<df:Toplist runat="server" ToplistId="123" Limit="10" Layout="cards" />
```

Trivially reliable. Right answer for fixed page furniture (sidebars, hub pages).

### 9.2 Editor placement — token expansion (the shortcode analogue)

Editors need to drop a toplist into the middle of an article body, exactly as
`[dataflair_toplist id="123"]` does today. Two implementations, in order of preference:

**Preferred — a CMS content hook.** If article HTML is emitted through a single code
path, one line does it:

```csharp
html = DataFlairTokens.Expand(html);   // finds [dataflair_toplist …], renders, replaces
```

Safe, fast, testable, and scoped to exactly the content that should contain tokens.
**This is worth negotiating for**, and question 3 in §2 exists to find it.

**Fallback — `Response.Filter` in the HttpModule.** Zero host code changes: attach a
stream filter on `PreRequestHandlerExecute`, only when `Content-Type` starts with
`text/html`, and only rewrite when a cheap `IndexOf("[dataflair_toplist")` hits.

Honest caveats, because this is the option that bites: it buffers the response (memory
cost on large pages), it can interact badly with IIS dynamic compression module ordering,
and it breaks under unbuffered/streamed responses. It is a legitimate ship-it fallback,
not the design goal.

### 9.3 Affiliate redirect

`/go/?campaign=NAME` registered via `RouteTable` → look up the tracker URL → validate it
is `http(s)` and well-formed → `301`. Miss or invalid → `404` with no-cache headers.
Identical semantics to `CampaignRedirectHandler`.

Two changes from WordPress:

- The campaign → tracker map lives in `DataFlairCache` (a real table), not memory (§10.3).
- Add a **Cloudflare bypass rule for `/go/*`** so redirects are never cached, and so click
  volume stays visible in origin logs.

---

## 10. What cannot port 1:1 — decide these explicitly

### 10.1 The `review` CPT is the biggest delta

The WordPress plugin leans on a WordPress-only content type in three places:

1. It **auto-creates draft `review` posts** at sync time for brands that lack one.
2. It reads `_review_pros` post meta to populate the card's three feature bullets.
3. It resolves the Read Review URL to the published review's permalink, matching by slug
   variants and by `_review_brand_id` when the live slug differs (`brand-india` vs `brand`).

None of that exists on Cricket World. **The port cannot create content in their CMS**, and
it should not try. Replacement design:

- **Read Review URL** resolves as: `review_url_override` → `cached_review_url` (populated
  by an admin-run reconcile against a configured URL pattern or a CSV/API mapping supplied
  by the Cricket World team) → configured pattern e.g. `/betting/reviews/{slug}/` →
  affiliate CTA. Same cascade shape, host-appropriate middle steps.
- **Feature bullets** come from `editorial_pros` / `editorial_cons` columns on the brands
  table, editable in the Brands admin screen — replacing `_review_pros`. Block-level
  pros/cons overrides continue to win, unchanged.
- **The "only show Read Review when a real review exists" rule survives**: the link renders
  only when an override or a reconciled `cached_review_url` is present. No dead links.

If Cricket World *does* have review articles with a queryable URL (question 8 in §2), step
two becomes a real resolver against their content store and the parity is close to exact.

### 10.2 No auto-update

A compiled DLL cannot self-update the way a GPL PHP plugin can, and it should not try —
overwriting a loaded assembly on a live IIS app is not a thing to build on purpose.
Replacement: publish to a private NuGet feed, and add a Dashboard tile that checks the
feed and says "2.3.0 available — you are on 2.2.11". Deployment stays a deliberate act.

### 10.3 Transients are not `MemoryCache`

WordPress transients live in the database, so they survive PHP process restarts. .NET's
`MemoryCache` does not survive an app-pool recycle — and IIS recycles pools routinely
(idle timeout, memory limits, nightly schedules).

The campaign → tracker map is written at **render** time and read at **redirect** time.
Put it in `MemoryCache` alone and a recycle between page view and click turns every
affiliate CTA into a 404. **That is revenue loss, silently.**

Rule for the port: `IKeyValueStore` is read-through `MemoryCache` over the `DataFlairCache`
table. Memory is the fast path; the table is the truth. Non-durable caches (the 12 h
schema-ok flag) may stay memory-only.

Better still, and worth doing in Phase 3: write the campaign → tracker map at **sync**
time rather than render time, so a redirect works for any campaign in the catalogue
whether or not its card has been rendered yet.

### 10.4 No Gutenberg

Gutenberg gives editors a visual block with an inspector panel. Realistic replacements,
best-effort first:

1. **Editor plugin** (CKEditor 5 / TinyMCE) with an "Insert Toplist" button that opens a
   picker and inserts the token — closest to the current experience. Depends on question 4.
2. **Admin picker page** that renders a searchable toplist list with a copy-token button
   and a live preview. Works regardless of editor. **Ship this in Phase 3 either way** —
   it is the fallback and the QA tool.
3. Server-side preview endpoint so editors can see a rendered toplist before publishing.

The block's colour/customisation attributes become token attributes plus site-wide defaults
in Settings, so the capability survives even where the UI is plainer.

---

## 11. The Cloudflare cache problem — read this before Phase 1

**This is the highest-risk item in the port.** The WordPress plugin calls
`signalUncacheable()` on *every* toplist render: it defines `DONOTCACHEPAGE` and sends
no-cache headers, because a geo-gated toplist cached by a page cache would serve one
country's content to another.

Cricket World sits behind Cloudflare. If Cloudflare full-page-caches an article containing
a country-scoped toplist, the first visitor's country is served to everyone until the cache
expires. At best it is wrong content; for iGaming offers it is a **compliance exposure** —
serving a restricted market an offer it must not see.

Blanket-disabling cache on every page with a toplist is the safe option and a serious
performance regression on a high-traffic sports site. So decide per toplist:

| Toplist `geo_type` | Cacheability | Mechanism |
|---|---|---|
| `global` | **Fully cacheable** — output is identical for everyone | Normal cache headers. No change. |
| `country` / `market` | Not cacheable as-is | One of the three below |

For the geo-scoped minority:

- **Option 1 — `Cache-Control: private` on that page.** Simplest, correct, costs origin
  load only on the affected pages. **Default recommendation.**
- **Option 2 — Cloudflare cache key by country.** Best of both: full CDN caching, one
  variant per country. Requires an Enterprise-tier custom cache key (question 7).
- **Option 3 — client-side island.** SSR a placeholder, hydrate from
  `/dataflair/api/v1/toplists/{id}/casinos` in the browser. Page stays fully cacheable;
  the toplist becomes invisible to crawlers. **Acceptable only for below-the-fold or
  non-SEO placements** — never for the main comparison table on a money page.

The renderer must therefore report its cacheability upward (`global` vs geo-scoped) so the
page can set headers correctly. Design that seam in Phase 1, not as an afterthought:
retrofitting it means auditing every call site.

---

## 12. Security model

Everything the plugin does today, translated:

| Control | Implementation |
|---|---|
| SQL injection | Dapper parameters everywhere. No string concatenation into SQL. Lint for it. |
| XSS | Razor auto-encoding + explicit `HtmlAttributeEncode` for attributes. |
| URL injection in `href` | Keep `UrlValidator`: scheme allowlist (`http`/`https` only), reject `javascript:`/`data:`. Applies to tracker links and logo URLs, which are third-party data. |
| CSRF on admin actions | `ValidateAntiForgeryToken` on every mutating endpoint. |
| Admin authorisation | Require the host's admin auth. If none is exposable, gate on a config secret **plus** an IP allowlist, and say so loudly in the docs — a bare shared secret on a public path is not enough for a screen that can rewrite affiliate URLs. |
| Sync trigger auth | Bearer token from config, constant-time compared. |
| Secrets at rest | API token in `web.config` `appSettings` (encryptable with `aspnet_regiis -pe`), **not** in the settings table. Diverges from WordPress deliberately — `wp_options` is not a good place for a bearer token either. |
| SSRF on sync | Base URL restricted to a configured host allowlist; no user-supplied URL reaches `HttpClient`. |
| Response caps | 15 MB cap retained — an unbounded upstream response is a memory-exhaustion vector. |
| Admin QA geo override | `?dataflair_geo=` honoured **only** for authenticated admins, exactly as today. Default-deny if the auth check is unavailable. |

---

## 13. Delivery phases

Each phase is independently demoable. No phase depends on a later one.

| Phase | Scope | Exit criteria | Est. |
|---|---|---|---|
| **0. Discovery + pilot** | Answer §2 questions. Stand up a JS-embed pilot (Option B) on one staging page to prove API access and data shape end-to-end. | Stack questions answered in writing; a real toplist visibly rendering on a Cricket World staging page. | 1 wk |
| **1. Core + data** | `Core` + `Data.SqlServer`. Models, repositories, migrations, settings/cache stores. Port the model + repository tests. | `dotnet test` green; migrations run clean against an empty DB and are idempotent on re-run. | 2 wks |
| **2. Sync engine** | API client, retry/caps/budget, toplist + brand sync, progressive split, logo download, sync history, integrity checker. | Full catalogue syncs from Sigma into SQL Server; row counts and JSON payloads match a WordPress instance synced from the same account. | 2 wks |
| **3. Rendering + placement** | Razor templates (card, table, accordion), view-models, review-URL resolver, geo gate, token expander, Razor helper, WebForms control, assets handler, `/go/` redirect. | A staging page renders a toplist **byte-comparable** to WordPress output for the same toplist ID; `/go/` redirects correctly; read-only render test passes. | 3 wks |
| **4. Admin** | Five screens, JSON endpoints, ported admin JS, anti-forgery + auth, editorial pros/cons, toplist picker. | An operator can sync, browse, edit review URLs, run tests, read logs, and change settings without touching the DB. | 3 wks |
| **5. Geo + cache hardening** | Cacheability signalling, Cloudflare rules, per-country QA matrix, optional hydration island. | Verified: a `country` toplist never leaks across countries through the CDN. Signed off against the §11 decision. | 1.5 wks |
| **6. Hardening + handover** | Security review, load test, docs, NuGet packaging, runbook, version-check tile. | Production deploy; runbook handed over; rollback rehearsed. | 1.5 wks |

**≈14 developer-weeks** for one experienced .NET developer, excluding Cricket World's own
review and deploy cycles. Phases 1–2 can run in parallel with 3 if two developers are
available, taking it to roughly 9–10 calendar weeks.

---

## 14. Testing strategy

- **Port the PHPUnit suite.** 112 tests exist; the ~95 unit tests map to xUnit almost
  mechanically because the classes under test are already interface-driven. This is the
  single highest-leverage activity in the port — it converts "we rewrote it and it looks
  right" into "it behaves the same".
- **Pin the invariants that earned their tests the hard way:**
  - render is read-only (no HTTP, no writes, no media sideload),
  - render query count is flat in item count (H7 prefetch),
  - geo gate default-denies on an unresolved country,
  - response size and wall-clock caps hold,
  - `/go/` 404s rather than open-redirects on an invalid stored URL.
- **Golden-HTML tests.** Render the three fixtures in `tests/phpunit/fixtures/` through both
  the PHP and .NET renderers and diff. Any difference must be a deliberate, recorded
  decision. This is what keeps the ported CSS honest.
- **Contract tests against a recorded API.** Capture real Sigma responses once, replay them
  in CI, so sync tests neither hit the network nor rot silently.
- **Manual geo matrix.** Cloudflare `CF-IPCountry` spoofed across ~8 countries × 3 toplist
  geo types, checked both cold and warm through the CDN.

---

## 15. Open decisions

| # | Decision | Owner | Blocks |
|---|---|---|---|
| 1 | WebForms vs MVC vs both | Cricket World eng | Phase 3 |
| 2 | DB access + DDL rights | Cricket World eng | Phase 1 |
| 3 | Content hook vs `Response.Filter` for tokens | Cricket World eng | Phase 3 |
| 4 | Editor integration target | Cricket World eng | Phase 4 |
| 5 | Cloudflare tier → §11 option 1, 2 or 3 | Mex + CW | Phase 5 |
| 6 | Review URL source of truth (§10.1) | Mex + CW editorial | Phase 3 |
| 7 | Admin auth mechanism | Cricket World eng | Phase 4 |
| 8 | Sync scheduling host (Task Scheduler / Hangfire / external cron) | Cricket World ops | Phase 2 |
| 9 | Is this Cricket-World-specific, or the first of N ASP.NET sites? | Mex | Phase 1 — decides how much goes in `Core` vs a host adapter |

Decision 9 is worth settling early. If DataFlair intends to sell this to other ASP.NET
publishers, the host-specific parts (review URL resolution, admin auth, content hook,
asset conventions) should sit behind a small `IHostAdapter` from day one, with Cricket
World as the first implementation. That costs little in Phase 1 and a lot to retrofit.

---

## 16. Summary

The WordPress plugin's domain logic — sync, geo, render, redirect — ports cleanly, because
the last few refactor phases already pushed WordPress out to the edges. The work is
concentrated in re-creating the platform services WordPress gave away for free: the options
store, transients, cron, the shortcode pipeline, the block editor, the admin framework, and
the review content type.

Three things decide whether this port succeeds:

1. **Getting a real content hook** instead of falling back to `Response.Filter` (§9.2).
2. **Solving geo-vs-CDN-cache deliberately** rather than discovering it in production (§11).
3. **Replacing the `review` CPT with an explicit, editor-owned data source** instead of
   pretending WordPress's content model exists on the other side (§10.1).

Everything else is careful, well-understood translation.
