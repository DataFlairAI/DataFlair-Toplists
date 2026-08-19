# DataFlair Toplists for ASP.NET — Port Plan (Cricket World)

> **Status:** to be implemented · **Created:** 2026-08-18 · **Owner:** Mex
> **Target host:** cricketworld.com — custom CMS on Microsoft ASP.NET (CLR 4.0.30319 → .NET Framework 4.x), fronted by Cloudflare
> **Source of truth:** this repo at v2.2.11 (~18k LOC PHP, 112 PHPUnit tests)
> **Deliverable:** `DataFlair.Toplists` — a drop-in .NET class library that gives a non-WordPress ASP.NET site the same toplist capability this plugin gives WordPress.
> **API contract:** see [Appendix A](#appendix-a--dataflair-api-contract-reverse-engineered-from-this-plugin) — endpoints, payloads, error semantics and the seven upstream gaps this plugin already defends against.

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
| **Preact + Goober** already loaded | **Nothing.** Noted for completeness only. Preact being bundled somewhere on the site does not make it a usable global for us, Goober is CSS-in-JS we have no use for (our card CSS is plain CSS), and the one optional island in §11 is ~30 lines of vanilla JS. Do not take a dependency on either. |
| **Lozad.js** (lazy loading) | A house convention to be aware of, **not one to copy** — see §8.6. Native `loading="lazy"` is strictly better for our logos. Check whether their global JS rewrites `img` tags site-wide, because that would fight us. |
| Google Tag Manager | CTA/impression tracking should push to `dataLayer`, not invent its own beacon. |

### Answered (2026-08-18)

| # | Question | Answer | Consequence |
|---|---|---|---|
| 1 | WebForms, MVC5, or both? | **Both** | Ship both embed surfaces: `<df:Toplist>` server control **and** `@Html.DataFlairToplist()`. Both are thin wrappers over one renderer, so the cost is small — see §9.1. |
| 2 | DB access / DDL rights | **Create tables; whichever of DDL-at-deploy vs DBA-scripts is less friction** | Resolved by building **one** migration engine with two output modes — §8.2. No either/or needed. |
| 3 | Article content rendering | **Unknown — but the CMS appears to have "blocks of content" / block slots** | **This is the best news in the whole set.** A block system beats both a content hook and `Response.Filter`. Reframes §9.2 entirely. One question to their dev now: *"how do you add a new block type?"* |
| 4 | Deploy model | **Unknown — wants pros/cons** | Weighed in §9.4. Recommendation: build as a standalone solution that emits a NuGet package **and** works as a project reference. Same csproj either way, so the decision stays open at near-zero cost. |
| 5 | Admin authentication | **They have an auth mechanism** | Require it. No bespoke gate, no shared-secret-on-a-public-path compromise. §12 simplifies. |
| 6 | Cloudflare tier | **Free or Pro** | **Enterprise cache-key-by-country is off the table.** §11 loses its easiest option; a Workers-based alternative replaces it. |
| 7 | Brand-review content type | **Reviews exist; map manually per brand, as in WordPress** | §10.1 collapses from "biggest delta" to "port the existing override + bulk-pattern tooling". Big scope reduction. |
| — | Delivery model (§3) | **Model C — hybrid SSR + optional JS island** | Confirmed. SSR is the default path; the island exists only for the §11 geo-cache case. |

### Still open

1. **The block API (from answer 3).** How does a new block type get registered, what does its editing UI look like, and can a block declare "this page is not cacheable"? Everything in §9.2 depends on this.
2. **Which build/deploy model** (answer 4) — see §9.4 for the trade-off; needs their CI owner.
3. **Whether their global JS rewrites `<img>` tags** for Lozad, which would fight the native lazy-loading in §8.6.
4. **Reconcile Appendix A against `docs.dataflair.ai/api/toplist/`** — see §A.8. Still unread; blocked from the authoring environment.

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

**Recommendation: C — confirmed by the user on 2026-08-18.** SSR is non-negotiable — toplists are the commercial payload of
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
| `layout` attribute: `cards` (the only production display) vs `table` (operator debug aid, labelled **"Accordion Tables (Testing)"** in the block UI — not an editorial choice) | ✅ 1:1 — port both, keep the same UI framing so editors don't reach for the debug layout by mistake |
| `layout: grid` — 2/3-up responsive card grid | ➕ **new, not in WordPress** — CSS-only variant of `cards` on the same markup; see §8.11 |
| Product-type labels (casino / sportsbook / poker) | ✅ 1:1 |
| Review-URL cascade: `review_url_override` → published review permalink → `/reviews/{slug}/` → affiliate CTA | ⚠️ middle step needs a host-specific resolver — §10.1 |
| Stale-data banner after 3 days | ✅ 1:1 |
| **Render-time read-only guarantee**: no HTTP, no media sideload, no content writes | ✅ **carry across as a pinned test** — this is the most valuable invariant in the codebase |
| H7 batched brand-meta prefetch (flat query count regardless of item count) | ✅ 1:1 |
| Pre-computed `local_logo_url` + `cached_review_post_id` at sync time | ✅ 1:1 |
| Per-card/per-block visual customisation (colours, border radius, shadows, ribbon text, rating stars) | ❌ **currently non-functional in WordPress — do not port as-is.** See §8.10: rebuilt for real, not carried across. |
| `ctaMode` shortcode/block attribute | ❌ **also dead** — normalised and threaded through, never read downstream. Drop it rather than port a no-op. |
| Pros/cons overrides keyed by stable brand/item ID | ✅ 1:1 — this one is real; see §8.10 for why it's the control case |

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

### 5.1 Build and deploy model — NuGet package vs source-integrated

Question 4 in §2, weighed. This now sits alongside the decision to develop in **a separate
repo** (§17), which pushes hard toward packaging.

| | **NuGet package + DLL** | **Source-integrated project** |
|---|---|---|
| Update mechanism | Version bump, restore, deploy | Merge our code into their repo |
| Version pinning | Explicit and auditable | Whatever their branch happens to hold |
| Debugging into our code | Needs symbols — solved by shipping SourceLink + `.snupkg` | Native step-through, zero setup |
| IP / licensing separation | Clean — our code stays ours | Entangled once it lives in their repo |
| Local patching by their team | Discouraged; fixes come upstream | Easy — and then upstream fixes conflict with the drift |
| Works if this becomes a product for N publishers | **Required** | Falls apart immediately |
| Infrastructure needed | A feed — but a **local folder feed works**, no hosting required | None |
| Friction if their CI builds one solution | Adds a restore step | Zero |

**Recommendation: build it as a standalone solution that produces a NuGet package *and*
works as a plain project reference.** That is the same `.csproj` either way — the cost of
keeping both doors open is essentially zero, and it means their CI owner can choose without
blocking Phase 1.

Two practical notes:

- Ship the `.nupkg` as a file they can drop into a local folder feed. No hosted NuGet feed,
  no account, no auth to negotiate. Removes the most common objection.
- Include SourceLink and a symbols package from day one. The strongest real argument for
  source-integration is "we want to debug it", and shipping symbols removes that argument
  before it is made.

If §15 decision 9 comes back as "first of N publishers", this stops being a judgement call:
NuGet, no discussion.

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
tables, apply numbered upgrades, self-heal. Same 12-hour warm-path short-circuit.

Answer 2 in §2 asks for "whichever is less friction" between DDL-at-deploy and
DBA-supplied scripts. **Build both from one source and the question stops mattering:**

- **Apply mode** (default in dev/staging, and in prod if we have DDL rights) — the runner
  executes pending migrations on first request after deploy, exactly as the plugin does.
- **Script mode** — `DataFlair.Toplists.Migrate.exe --script` writes the exact SQL that
  *would* run to stdout, ready to hand a DBA. Same embedded scripts, same ordering, same
  version bookkeeping.
- **Verify mode** — when the runner has read-only rights it checks the schema matches the
  expected version and, if not, logs loudly and **refuses to sync** rather than writing into
  a shape it cannot vouch for.

One set of migration scripts, three ways to consume them. No divergence between what a DBA
runs and what the app expects, which is the actual failure mode this question is about.

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

**Lazy loading — do not copy the house Lozad convention.** Lozad was the right answer in
2018; in 2026 native `loading="lazy"` beats it on every axis that matters here:

- Lozad's `data-src` pattern **hides the URL from the browser's preload scanner**, so the
  image can't be prioritised or fetched early. Native `loading="lazy"` keeps the real
  `src`, so the browser schedules it intelligently.
- Lozad needs JS to run before *any* logo appears. Native needs nothing.
- Native costs 0 KB. Lozad costs a library plus an IntersectionObserver per image.

More important than either: **a toplist is usually at or near the top of the page — it is
the commercial payload.** Lazy-loading an above-the-fold image actively delays LCP, which
is the opposite of an optimisation. So:

```html
<img src="…" width="120" height="60" alt="…"
     loading="eager"  decoding="async"   <!-- positions 1–3 -->
     loading="lazy"   decoding="async"   <!-- positions 4+   -->
```

Always emit explicit `width`/`height` — without them the card reflows as logos land, which
is a CLS penalty on every toplist on the site.

**One thing to verify on their side** (§2, still-open #3): if Cricket World's global JS
rewrites `<img>` tags site-wide into the Lozad pattern, it will strip our `src` and undo
this. If so we need an opt-out class, or we accept their convention for consistency and
eat the LCP cost knowingly rather than by accident.

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


### 8.10 Customization system — rebuilt correctly, not ported as-is

**Finding, worth stating plainly: the WordPress plugin's per-card visual customisation does
not currently work.** This surfaced while scoping this section — tracing where the block's
colour attributes actually land, expecting to write "✅ ports 1:1," found instead a complete,
plausible-looking pipeline with a dead end.

The trace, end to end:

1. `dataflair-toplists/toplist` block declares **22 style attributes** — `ribbonBgColor`,
   `ribbonText`, `rankBgColor`, `rankBorderRadius`, `brandLinkColor`, `bonusLabelStyle`,
   `bonusTextStyle`, `featureCheckBg`, `featureCheckColor`, `featureTextColor`, `ctaBgColor`,
   `ctaHoverBgColor`, `ctaTextColor`, `ctaBorderRadius`, `ctaShadow`, `metricLabelStyle`,
   `metricValueStyle`, `rgBorderColor`, `rgTextColor`, and others — editable in the block
   inspector under panels like "Ribbon / Highlight Bar" (`src/index.js`).
2. Four of them (`ribbonBgColor`, `ribbonTextColor`, `ctaBgColor`, `ctaTextColor`) also have
   a **site-wide default UI** on the Settings → Customizations tab, stored as `wp_options`.
3. `ToplistBlock::defaults()` merges the option-sourced defaults with the block's saved
   attributes and passes all 22 into `$shortcode_atts`.
4. `ToplistShortcode::render()` carries them through into `CasinoCardVM::$customizations`.
5. `CardRenderer::render()` extracts `$customizations` into the template's local scope
   (`$customizations = $vm->customizations;`) before including `casino-card.php`.
6. **`views/frontend/casino-card.php` — 531 lines — never reads `$customizations`, and
   never references any of the 22 attribute names.** Grepping the template for every one of
   them returns nothing. The rendered card uses fixed CSS classes
   (`.casino-card-ribbon`, `.casino-cta-button`, `.rating-star`, …) from `assets/style.css` /
   `editor.css`, unconditionally, regardless of what the block or Settings screen holds.

An operator can set the CTA button to red in Settings, save it, see no error, and the button
stays whatever colour the stylesheet says. **Star rating colour has no hook at all** —
`.rating-star { color: #fbbf24; }` is a flat rule in `editor.css`; it was never a block
attribute, on-CPT setting, or option in the first place.

**Why this almost certainly happened:** the attribute values are Tailwind utility-class
syntax — `"brand-600"`, `"text-gray-900 text-lg leading-6 font-semibold"`, `"shadow-md"`.
Neither this plugin nor its target sites ship Tailwind (confirmed: no Tailwind config,
CDN reference, or `@apply` anywhere in this repo). Those strings were never going to render
as colours without a Tailwind build resolving them into real CSS. The card template's
underlying rewrite to fixed, plain CSS classes (the read-only render-time invariant work,
Phase 0A onward) most likely dropped the Tailwind-consuming markup and left the
attribute-threading plumbing in place, unnoticed because nothing exercises it end to end.

**Decision for the ASP.NET port: do not port this pipeline. Build the feature for real.**
Faithfully reproducing 22 inert fields would be strictly worse than not having them — a
control that visibly does nothing erodes trust in every other control on the same screen.
This is also worth a one-line heads-up on the WordPress side separately; flag if you'd like
that filed as its own issue on this repo — it's out of scope for this docs-only branch.

**Design — CSS custom properties, not utility-class strings:**

Real color inputs (hex/rgba from an actual colour picker, not a text field expecting
Tailwind syntax) resolve at render time into an inline `<style>` block scoped to a
per-instance attribute, consumed by `var()` fallbacks already built into the shipped CSS:

```html
<style>
  .df-toplist-x7f2 {
    --df-ribbon-bg: #e11d48;   --df-ribbon-text: #ffffff;   --df-ribbon-label: "Editor's Pick";
    --df-cta-bg: #2563eb;      --df-cta-bg-hover: #1d4ed8;  --df-cta-text: #ffffff;
    --df-star-color: #fbbf24;  --df-rank-bg: #f3f4f6;       --df-rank-text: #111827;
  }
</style>
<div class="dataflair-toplist df-toplist-x7f2"> … </div>
```

```css
/* shipped, unconditional CSS — falls back to today's fixed values when no override exists */
.casino-card-ribbon  { background: var(--df-ribbon-bg, #e11d48); color: var(--df-ribbon-text, #fff); }
.casino-cta-button   { background: var(--df-cta-bg, #2563eb); color: var(--df-cta-text, #fff); }
.casino-cta-button:hover { background: var(--df-cta-bg-hover, #1d4ed8); }
.rating-star          { color: var(--df-star-color, #fbbf24); }
.casino-position-badge{ background: var(--df-rank-bg, #f3f4f6); color: var(--df-rank-text, #111827); }
```

No Tailwind dependency, no build step, no JS required for the base case, and every property
has the current hardcoded value as its fallback — so an instance with no overrides renders
pixel-identical to today. Same cascade shape WordPress already advertises (and should have
delivered): **site-wide default in Settings → per-block/per-shortcode override.**

**Trimmed, real attribute set** — collapses the 22 Tailwind-string fields into 9 typed
properties. The multi-line utility-class fields (`bonusLabelStyle`, `bonusTextStyle`,
`metricLabelStyle`, `metricValueStyle`, `rgBorderColor`, `rgTextColor`) don't have a
faithful equivalent — they were arbitrary style strings, not single values — so they're
replaced with the specific properties worth exposing rather than carried over as free text:

| Property | CSS variable | New capability? |
|---|---|---|
| Ribbon background / text / label | `--df-ribbon-bg` / `-text` / literal string | Ports (was already real intent, just unwired) |
| CTA button background / hover / text | `--df-cta-bg` / `-bg-hover` / `-text` | Ports |
| Rank badge background / text | `--df-rank-bg` / `-text` | Ports |
| Brand name link colour | `--df-brand-link` | Ports |
| **Star rating colour** | `--df-star-color` | **New** — user-requested; had no hook in WordPress at all |

Border radius and shadow become one **card style preset** (`rounded` / `square` /
`elevated`) rather than four freeform strings — a bounded choice an operator can actually
reason about, and the choice a colour-picker UI can render as swatches instead of text
inputs prone to typos.

`layout` (Cards vs the Testing accordion table) and pros/cons overrides are unaffected by
any of this — they are the two customisation-adjacent features that were already real, and
they carry across unchanged (§4.3). `ctaMode` is dropped: same shape of dead code as the
style attributes, threaded and normalised but never read downstream — no reason to port a
second no-op.

### 8.11 Grid layout — a new `layout` value, not in WordPress today

User-requested (2026-08-19), working from a reference screenshot of a competitor's toplist.
The reference turned out to be the *same* row layout this plugin already renders — full-width
card, ribbon on #1, expandable "More information" — confirming the existing markup is already
on the right track. The actual ask is a **third `layout` value**: instead of one full-width
card per row, arrange 2 or 3 cards side by side on a wide viewport, collapsing responsively.

**Today's two values, for context:** `cards` (production, one full-width row per item) and
`table` (operator debug aid, §4.3). Neither lays out multiple cards side by side — nothing in
`assets/style.css` or `editor.css` places more than one `.casino-card-wrapper` per row; each
is simply a full-width block stacked under the last.

**Design: reuse the card partial verbatim, change only its container and internal flow
direction via a modifier class.** This keeps the "same DOM, styling does the work" principle
from §8.5 intact — no new Razor template, no new view-model, no duplicated markup to drift
out of sync with the row layout:

```html
<div class="dataflair-toplist dataflair-toplist--grid">
  <div class="casino-card-wrapper" data-position="1"> … same partial … </div>
  <div class="casino-card-wrapper" data-position="2"> … same partial … </div>
  <div class="casino-card-wrapper" data-position="3"> … same partial … </div>
</div>
```

```css
.dataflair-toplist--grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
  gap: 1.5rem;
  max-width: 1200px;   /* same cap as today's .dataflair-toplist */
}
/* the row layout's 4-column internal grid doesn't fit a 320px-wide cell —
   collapse each card to a single vertical column inside the grid variant only */
.dataflair-toplist--grid .casino-card-main {
  grid-template-columns: 1fr;
  gap: 0.75rem;
}
.dataflair-toplist--grid .casino-brand-col,
.dataflair-toplist--grid .casino-bonus-col,
.dataflair-toplist--grid .casino-features-col,
.dataflair-toplist--grid .casino-cta-col {
  border-left: none; border-top: none; padding-left: 0; padding-top: 0;
}
```

`repeat(auto-fit, minmax(320px, 1fr))` inside a 1200px container is what actually delivers
"3 up on desktop, 2 up on tablet, 1 up on mobile" — it's a size-based reflow, not a set of
manual breakpoints, so it degrades gracefully at any viewport width rather than jumping
awkwardly between two or three hand-picked ones:

| Container width | Columns that fit at min. 320px + 1.5rem gaps |
|---|---|
| ≥ ~1030px (desktop) | **3** |
| ~700–1029px (tablet) | **2** |
| < 700px (mobile) | **1** — visually identical to today's `cards` layout |

```
Desktop — 3 up (>= ~1050px):
┌────────────────────────────┐  ┌────────────────────────────┐  ┌────────────────────────────┐
│★ OUR TOP CHOICE            │  │                            │  │                            │
│[1] ┌────┐  GambleZen       │  │[2] ┌────┐  Malina          │  │[3] ┌────┐  BitStarz        │
│    │LOGO│  ★4.0/5          │  │    │LOGO│  ★4.5/5          │  │    │LOGO│  ★4.5/5          │
│    └────┘  Read Review →   │  │    └────┘  Read Review →   │  │    └────┘  Read Review →   │
│                            │  │                            │  │                            │
│WELCOME BONUS               │  │WELCOME BONUS               │  │WELCOME BONUS               │
│500% up to $5,450           │  │100% up to $750             │  │300% up to $500             │
│+ 350 Free Spins            │  │+ 200 Free Spins            │  │or 5 BTC + 180 FS           │
│[ Promo: WELCOME100 ⧉ ]     │  │                            │  │                            │
│                            │  │✓ 80+ providers             │  │✓ 500+ cryptos              │
│✓ Fast payouts              │  │✓ Tiered VIP                │  │✓ 10-min cashout            │
│✓ 11,000+ games             │  │                            │  │                            │
│                            │  │                            │  │                            │
│[    Visit Site →    ]      │  │[    Visit Site →    ]      │  │[    Visit Site →    ]      │
│More information +          │  │More information +          │  │More information +          │
└────────────────────────────┘  └────────────────────────────┘  └────────────────────────────┘
```

Design decisions worth pinning down explicitly, so the wireframe above isn't mistaken for
the full spec:

- **Ribbon.** Position 1's "OUR TOP CHOICE" bar renders on its own card, at that card's
  width — not spanning the whole row as it does in `cards` layout. If position 1 lands in
  column 2 or 3 (possible once `limit`/pagination interact with grid ordering), the ribbon
  moves with it. No special-casing needed — it's the same conditional (`position === 1`)
  the row layout already uses.
- **Feature bullets** cap at 2 in the grid variant, not 3 — vertical space is scarcer per
  card than in a 4-column horizontal strip. Configurable, not hardcoded: expose a
  `maxFeatures` option defaulting to 2 for `grid`, 3 for `cards`.
  - **Payment-method icons and the metrics grid move into the "More information" expandable
  panel only** — they don't fit a narrow card unexpanded, and they already live there today.
- **Column count is a *hint*, not a switch.** Expose it as `columns: auto | 2 | 3` on the
  layout options — `auto` is `repeat(auto-fit, minmax(320px, 1fr))` (the recommended
  default); `2` or `3` fixes `grid-template-columns: repeat(2, 1fr)` /
  `repeat(3, 1fr)` for an editor who wants a guaranteed count regardless of viewport,
  accepting that a fixed count won't reflow as gracefully on very narrow desktops.

**Scope note:** this is new in the ASP.NET port, not present in the WordPress plugin's CSS at
all. Because it's pure CSS on already-shared markup, it is also a low-risk **candidate to
backport to WordPress** later as its own change — flag if that's wanted; out of scope for
this docs-only branch.

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

### 9.2 Editor placement — three options, best first

Editors need to drop a toplist into the middle of an article, exactly as
`[dataflair_toplist id="123"]` does today.

**Option 1 — register a CMS block type. Strongly preferred.**

Answer 3 in §2 says the CMS has "blocks of content" and slots for them. If that is a real
block system, **this is the answer and the other two options are dead**. Registering a
`DataFlair Toplist` block type gives us, for free, everything the Gutenberg block gives us
in WordPress:

- a real editor UI with a toplist picker instead of a hand-typed token,
- typed, validated attributes instead of string parsing,
- placement anywhere a block can go, with no HTML-rewriting anywhere,
- and — critically for §11 — a **structured place to declare "this block makes the page
  uncacheable"**, which a token buried in an HTML blob can never do cleanly.

It also collapses §10.4 (the "no Gutenberg" delta) almost to nothing. This is the single
highest-leverage unknown left in the plan, which is why §2's still-open list leads with it.
**One question to their developer: *"how do you add a new block type?"***

**Option 2 — a CMS content hook.** If there is no block system but article HTML is emitted
through a single code path, one line does it:

```csharp
html = DataFlairTokens.Expand(html);   // finds [dataflair_toplist …], renders, replaces
```

Safe, fast, testable, scoped to exactly the content that should contain tokens.

**Option 3 — `Response.Filter` in the HttpModule.** Zero host code changes: attach a stream
filter on `PreRequestHandlerExecute`, only when `Content-Type` starts with `text/html`, and
only rewrite when a cheap `IndexOf("[dataflair_toplist")` hits.

Honest caveats, because this is the option that bites: it buffers the response (memory cost
on large pages), it can interact badly with IIS dynamic compression module ordering, and it
breaks under unbuffered/streamed responses. It is a legitimate ship-it fallback, **not** a
design goal — and given answer 3, probably not needed at all.

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

### 10.1 The `review` CPT — **resolved, and much smaller than feared**

Answer 7 in §2 settles this: Cricket World **has** review content, and the team is happy to
**map it manually per brand, exactly as they already do in WordPress**. That collapses what
was the plan's biggest delta into a modest one.

What the WordPress plugin does with its `review` CPT, and what happens to each:

| WP behaviour | Port |
|---|---|
| Auto-creates draft `review` posts at sync time for brands lacking one | **Dropped.** The port must not write into their CMS, and with manual mapping there is nothing to auto-create. Removing this also deletes a whole class of "plugin created 400 orphan drafts" support tickets. |
| Reads `_review_pros` post meta for the card's three feature bullets | **Replaced** by `editorial_pros` / `editorial_cons` columns on the brands table, edited in the Brands admin screen. Block-level pros/cons overrides still win. |
| Resolves Read Review to the published permalink, matching by slug variants and `_review_brand_id` | **Replaced** by `review_url_override` as the *primary* mechanism rather than a fallback. |

The resolution cascade simplifies to: `review_url_override` → configured pattern
(e.g. `/betting/reviews/{slug}/`) → affiliate CTA. The slug-variant and
`_review_brand_id` fuzzy-matching machinery — the fiddliest, most bug-prone part of the
WordPress implementation — **does not need porting at all**. Manual mapping is exact by
construction.

**Port the bulk tooling, because it is what makes manual mapping tolerable.** The plugin
already has `BulkApplyReviewPatternHandler`: select N brands, supply a pattern containing
`{slug}`, and it writes `review_url_override` for each. The workflow becomes:

1. Bulk-apply `/betting/reviews/{slug}/` across the whole catalogue — covers most brands in
   one action.
2. Hand-fix the exceptions in the Brands screen.
3. Add a **link checker** in Tools that HEADs every stored review URL and flags 404s.

That third step is new and worth building. Manual mapping's real failure mode is not the
initial setup — it is silent rot when editorial moves or unpublishes a review six months
later. A weekly check that surfaces broken review links costs a day and prevents dead links
sitting on money pages indefinitely.

**The "no dead links" rule survives unchanged**: the Read Review control renders only when
an override is present. A brand with no mapping simply shows no review link.

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

### 10.4 No Gutenberg — mostly resolved by §9.2

Gutenberg gives editors a visual block with an inspector panel. Whether that experience
survives the port now depends entirely on the answer to still-open question 3 (§2): does
Cricket World's CMS have a real content-block system?

- **If yes** — §9.2 Option 1 (register a `DataFlair Toplist` block type) **is** the Gutenberg
  replacement, not an approximation of one: a picker, typed inspector-style attributes, and
  placement anywhere a block can go. This subsection is then nearly moot.
- **If no** — fall back to what Gutenberg-parity actually needs, best-effort first:
  1. **Editor plugin** (CKEditor 5 / TinyMCE) with an "Insert Toplist" button that opens a
     picker and inserts the token — closest to the current experience. Depends on question 4.
  2. **Admin picker page** that renders a searchable toplist list with a copy-token button
     and a live preview. Works regardless of editor. **Ship this in Phase 3 either way** —
     it is the fallback and the QA tool.
  3. Server-side preview endpoint so editors can see a rendered toplist before publishing.

**The inspector's visual controls do not carry over as-is, and that is by design, not a
gap.** In WordPress today those controls edit 22 attributes that the rendered card never
reads (§8.10). Whichever placement path wins, its settings UI exposes only the smaller, real
attribute set §8.10 defines — layout, item limit, pros/cons overrides, and the rebuilt
colour/typography tokens that actually reach the page.

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
| `country` / `market` | Not cacheable as-is | One of the options below |

**Answer 6 in §2 constrains this: Cricket World is on Cloudflare Free or Pro.** Custom cache
keys via Cache Rules are Enterprise-only, so the easiest option is gone. What remains:

- **Option 1 — `Cache-Control: private, no-store` on pages carrying a geo-scoped toplist.**
  Simplest, unambiguously correct, and the cost is bounded to the affected pages rather than
  the whole site. **Default recommendation.** Pair it with a Cloudflare Cache Rule that
  bypasses cache for those URLs, so origin headers and edge behaviour agree.

- **Option 2 — a Cloudflare Worker that varies the cache key by country.** Available on
  Free and Pro (Workers is priced separately from the zone plan, and the paid tier is
  inexpensive). A Worker reads `request.cf.country`, builds a synthetic cache key like
  `https://host/path#country=IN`, and uses the Workers Cache API to store one variant per
  country. That recovers most of the Enterprise behaviour without the Enterprise plan.

  Caveats to validate before committing: the Workers Cache API is **per-datacenter**, not
  tiered, so hit rates are lower than native caching; every request costs a Worker
  invocation; and cache-key logic living at the edge is a second place where geo rules can
  drift out of sync with the origin's. Prototype it in Phase 5 and measure before adopting.

- **Option 3 — client-side island.** SSR a placeholder, hydrate from
  `/dataflair/api/v1/toplists/{id}/casinos` in the browser. Page stays fully cacheable; the
  toplist becomes invisible to crawlers. **Acceptable only for below-the-fold or non-SEO
  placements** — never for the main comparison table on a money page. (~30 lines of vanilla
  JS. It does not need Preact, whatever else the page has loaded.)

**Recommended sequence:** ship Option 1, measure the origin-load cost on the geo-scoped
pages, and only reach for Option 2 if that cost turns out to matter. Most toplists are
expected to be `global`, in which case this whole problem affects a small slice of pages and
Option 1 is simply the right answer permanently.

The renderer must report its cacheability upward (`global` vs geo-scoped) so the page can set
headers correctly. Design that seam in Phase 1, not as an afterthought: retrofitting it means
auditing every call site. **If the CMS block system from §9.2 exists, a block can declare
this directly** — which is the cleanest version of the seam and another reason that question
matters.

---

## 12. Security model

Everything the plugin does today, translated:

| Control | Implementation |
|---|---|
| SQL injection | Dapper parameters everywhere. No string concatenation into SQL. Lint for it. |
| XSS | Razor auto-encoding + explicit `HtmlAttributeEncode` for attributes. |
| URL injection in `href` | Keep `UrlValidator`: scheme allowlist (`http`/`https` only), reject `javascript:`/`data:`. Applies to tracker links and logo URLs, which are third-party data. |
| CSRF on admin actions | `ValidateAntiForgeryToken` on every mutating endpoint. |
| Admin authorisation | **Resolved (§2 answer 5): Cricket World has an auth mechanism — require it.** No bespoke gate, no shared-secret fallback. The admin screens can rewrite affiliate URLs, so they must sit behind the same auth as the rest of their admin, not a parallel one. |
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
| **0. Discovery + pilot** | Answer §2 questions. **Reconcile Appendix A against docs.dataflair.ai** and answer §A.8. Stand up a JS-embed pilot (Option B) on one staging page to prove API access and data shape end-to-end. | Stack questions answered in writing; Appendix A corrected and §A.8 closed; a real toplist visibly rendering on a Cricket World staging page. | 1 wk |
| **1. Core + data** | `Core` + `Data.SqlServer`. Models, repositories, migrations, settings/cache stores. Port the model + repository tests. | `dotnet test` green; migrations run clean against an empty DB and are idempotent on re-run. | 2 wks |
| **2. Sync engine** | API client, retry/caps/budget, toplist + brand sync, progressive split, logo download, sync history, integrity checker. Build against Appendix A; if §A.8 q1/q2 came back positive, use the delta filter and/or a webhook receiver instead of full resync. | Full catalogue syncs from Sigma into SQL Server; row counts and JSON payloads match a WordPress instance synced from the same account. | 2 wks |
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

### Closed

| # | Decision | Outcome |
|---|---|---|
| 1 | WebForms vs MVC vs both | **Both** — §9.1 |
| 2 | DB access + DDL rights | **Create tables; one migration engine, three modes** — §8.2 |
| 5 | Cloudflare tier → §11 option | **Free/Pro** → Enterprise cache key out; ship option 1, prototype option 2 — §11 |
| 6 | Review URL source of truth | **Manual per-brand mapping + bulk pattern tool** — §10.1 |
| 7 | Admin auth mechanism | **Use their existing auth** — §12 |
| — | Delivery model | **Model C — hybrid SSR + optional island** — §3 |
| — | Repo | **New standalone repo**, not a folder in this one — §17 |

### Still open

| # | Decision | Owner | Blocks |
|---|---|---|---|
| 3 | Does the CMS have a real block system, and how is a block type registered? | Cricket World eng | Phase 3 — **highest-leverage unknown left** (§9.2) |
| 4 | NuGet package vs source-integrated build | Cricket World CI owner | Phase 6 — trade-off in §5.1; near-zero cost to defer |
| 8 | Sync scheduling host (Task Scheduler / Hangfire / external cron) | Cricket World ops | Phase 2 |
| 9 | Is this Cricket-World-specific, or the first of N ASP.NET sites? | Mex | Phase 1 — decides how much goes in `Core` vs a host adapter |
| 10 | Reconcile Appendix A with `docs.dataflair.ai/api/toplist/`; settle §A.8 | Mex | Phase 0 → gates Phase 2 sync design |
| 11 | Does their global JS rewrite `<img>` into the Lozad pattern? | Cricket World eng | Phase 3 — §8.6 |

Decision 9 is worth settling early. If DataFlair intends to sell this to other ASP.NET
publishers, the host-specific parts (review URL resolution, admin auth, block registration,
asset conventions) should sit behind a small `IHostAdapter` from day one, with Cricket
World as the first implementation. That costs little in Phase 1 and a lot to retrofit.
Building in a **separate repo** (§17) already keeps that door open.

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

---

## 17. Where this gets built — a new repo, not a folder here

**Decision (2026-08-18): the port lives in its own repository.** Suggested name
`DataFlair-Toplists-AspNet` under the same `DataFlairAI` org.

Why not a folder in this repo:

- **Different everything.** Different language, toolchain (`dotnet`/MSBuild vs
  Composer/`wp-scripts`), test runner (xUnit vs PHPUnit), CI matrix, and release artefact
  (NuGet package vs GitHub release zip). The two CI workflows here would either have to
  learn to ignore a large C# tree or start failing on it.
- **This repo's release checklist is WordPress-shaped** — version bumps in
  `dataflair-toplists.php`, `plugins_api` changelog blocks, the strike-odds rsync rule.
  None of it applies to a DLL, and interleaving two release processes in one repo is how
  both get done badly.
- **§15 decision 9 stays open.** If this becomes a product for N ASP.NET publishers, it
  needs its own versioning and issue tracker from day one. A separate repo costs nothing now
  and avoids an awkward extraction later.
- **Clean IP separation** if the deploy model ends up source-integrated (§5.1).

What the new repo takes *from* here:

| Asset | Use |
|---|---|
| `tests/phpunit/fixtures/*.json` (3 files) | Copy verbatim. They become the .NET contract-test fixtures and the fake-API replay corpus. |
| `views/frontend/casino-card.php` | The markup contract for the Razor port — same classes, same DOM, same SVGs. |
| `assets/style.css`, `assets/editor.css` | Copy verbatim as embedded resources. |
| `assets/admin/*.js` (~50 KB jQuery) | Port with URL + anti-forgery changes. |
| This plan + Appendix A | Copy into the new repo's `docs/` as its founding spec. |

The two repos stay linked by the API contract in Appendix A, not by code.

**Implementation detail — repo scaffold, milestone-level task breakdown, and the local test
harness — lives in [plan 03](03-aspnet-implementation-plan.md).** This document stays the
"what and why"; 03 is the "how".

---

## Appendix A — DataFlair API contract (reverse-engineered from this plugin)

> **Provenance note.** `docs.dataflair.ai` is blocked by this session's egress policy
> (gateway returns 403 on CONNECT for both `docs.dataflair.ai` and `dataflair.ai`), so
> this appendix is derived from the working production integration in this repo rather
> than from the published documentation: `src/Http/`, `src/Sync/`, `src/Database/ToplistDataStore.php`,
> `includes/DataIntegrityChecker.php`, the three fixtures in `tests/phpunit/fixtures/`,
> and `tests/phpunit/Integration/V2ApiBrandsTest.php`.
>
> **Treat this as authoritative on observed behaviour and provisional on intent.** It
> describes what the API demonstrably returns and what this plugin defends against — not
> what is contractually guaranteed. Before Phase 2 starts, reconcile it against
> docs.dataflair.ai and record any divergence here. Where the two disagree, the docs win
> on *intent*; this appendix wins on *what you must handle in production*.

### A.1 Transport and auth

| | |
|---|---|
| Base URL | `https://{tenant}.dataflair.ai/api/v1` — **tenant-scoped**. `sigma` is one tenant; Cricket World will have its own. Hard-coded fallback in the plugin is `https://sigma.dataflair.ai/api/v1`. |
| Auth | `Authorization: Bearer {token}` |
| Accept | `application/json` |
| Versioning | Path segment. Brands can opt into v2 by rewriting `/api/v\d+$` → `/api/v2`; **toplists are always v1** today. |
| Optional | HTTP Basic credentials injected into the URL for password-gated staging environments — separate from, and additional to, the bearer token. |

Base URL resolution order (port this cascade as-is): configured setting → base extracted from a cached endpoint URL via `^(https?://[^/]+/api/v\d+)/` → hard-coded fallback. HTTPS is forced.

### A.2 Endpoints

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/toplists?per_page={n}&page={n}` | Paginated list. **Returns the full nested payload** — items, brand, offer, trackers — not stubs. `?include=items` is a no-op (verified 2026-04-25: identical bytes, identical time). |
| `GET` | `/toplists/{id}` | Single toplist. Same nested shape. Used as the per-ID fallback when a bulk page 5xxs. |
| `GET` | `/brands?per_page={n}&page={n}` | Paginated brand catalogue. v1 default, v2 opt-in. |

Connection test hits `{base}/toplists` with no query string.

**Because the list endpoint already returns everything, the .NET sync engine should default to
list-paging and treat per-ID fetches purely as the degradation path** — same as the plugin.
Do not design a two-phase "list then hydrate" fetch; it would double the request count for no gain.

### A.3 Envelopes and pagination

List responses:

```jsonc
{ "data": [ { /* toplist or brand */ }, … ],
  "meta": { "last_page": 4, "total": 87 } }
```

Single responses: `{ "data": { … } }`

Rules the port must reproduce:

- `meta.last_page` drives the batch loop; **default to `1` when absent** (the plugin caches it
  for an hour and falls back to a stored value). `meta.total` is informational.
- Missing `data` is a hard error, not an empty page.
- v2 brand payloads may use **`data.listItems`** where v1 uses **`data.items`**. Read both.
- **Normalization rule, easy to miss and expensive to get wrong:** when persisting an element
  taken from a *list* response, the plugin re-wraps it as `{"data": {…toplist…}}` before
  storing (`ToplistSyncService` line ~181). The stored blob therefore always has the
  single-toplist envelope shape regardless of which endpoint produced it, and every reader
  — shortcode, block, table renderer — accesses `data.data.items`. The .NET store must
  apply the same wrap or every render breaks.

### A.4 Toplist payload

Top-level fields the integrity checker treats as **required**: `id`, `name`, `status`,
`version`, `template`, `site`, `geo`, `items`.

Fields it treats as **expected-but-tolerated** (upstream is known to omit them on some
tenants — warn, persist, never block the sync): `slug`, `currentPeriod`, `publishedAt`,
`shortcode`.

```jsonc
{ "data": {
  "type": "toplist", "id": 55, "name": "…", "status": "…", "locked": false,
  "version": "…", "slug": "…", "currentPeriod": "…", "publishedAt": "…",
  "shortcode": "…", "createdAt": "…", "updatedAt": "…",
  "template": { "id": 7, "name": "…", "productTypeId": 1, "productType": "casino",
                "listClassificationTypeId": 2, "listClassificationType": "…" },
  "site":     { "id": 3, "domain": "…" },
  "geo":      { "geo_type": "country|market|global", "name": "Brazil",
                "code": "BR", "coveredCountries": ["…"] },
  "items": [ {
    "type": "item", "id": 301, "position": 1, "isLocked": false, "dealId": "…",
    "brand": { "id": 201, "name": "…", "slug": "…", "rating": 4.8,
               "logo": { "rectangular": "…", "square": "…", "backgroundColor": "#1a1a2e" },
               "licenses": ["MGA", "…"], "paymentMethods": ["Visa", "…"],
               "restrictedCountries": ["US", "FR"] },
    "offer": { "id": 401, "name": "…", "offerTypeId": 1, "offerTypeName": "…",
               "offerText": "…", "currencies": ["…"],
               "has_free_spins": true, "bonus_wagering_requirement": 30,
               "bonus_expiry_date": "…", "bonus_code": "…",
               "minimum_deposit": 10, "max_payout": null, "max_bonus_amount": 500,
               "is_sticky_bonus": false, "minimum_odds": null, "free_bet_value": null,
               "stake_returned": null, "bet_type": null, "tournament_ticket_value": null,
               "rakeback_percentage": null, "free_tickets": null,
               "geos": { "countries": ["Brazil"], "markets": ["LATAM"] },
               "trackers": [ { "id": 401, "campaignName": "Brazil Main",
                               "trackerLink": "https://…", "tcLink": "https://…",
                               "pageType": "homepage",
                               "geos": { "countries": ["Brazil"], "markets": [] } } ] }
  } ] } }
```

`geo.geo_type` drives the render gate and, on the .NET side, page cacheability (§11). Note
the shape varies by type: `country` carries `code`, `market` carries `coveredCountries`,
`global` carries neither. The fixture shows `geo` with only `geo_type` + `name` — **`code`
can be absent even on a `country` toplist**, which is exactly why `GeoRenderGate`
default-denies rather than guessing.

**Offer field sets are product-type-dependent.** Casino offers populate the bonus/spins
fields; sportsbook offers populate `minimum_odds`, `free_bet_value`, `stake_returned`,
`bet_type`; poker offers populate `tournament_ticket_value`, `rakeback_percentage`,
`free_tickets`. Everything else is `null`. The .NET models must treat every one of these as
nullable — do not infer required-ness from a single tenant's data.

### A.5 Brand payload (`/brands`)

Different key casing from the brand object nested inside a toplist item — **this is a real
trap**. The nested one uses `rating`/`logo`; the catalogue one uses `brandStatus`,
`productTypes`, `topGeos`, `offersCount`, `classificationTypes`:

```jsonc
{ "id": 201, "name": "…", "slug": "…",
  "brandStatus": "Active",
  "productTypes": ["Casino", "Sportsbook"],
  "licenses": ["…"], "classificationTypes": ["…"],
  "topGeos": { "countries": ["…"], "markets": ["…"] },
  "offersCount": 4,
  "offers": [ { "trackers": [ … ] } ] }
```

Mapping rules the port must reproduce exactly:

| Column | Derivation |
|---|---|
| filter | **Skip any brand whose `brandStatus !== 'Active'`** — not stored at all |
| skip | Skip any brand with no `id` |
| `product_types` | `implode(', ', productTypes)` |
| `licenses` | `implode(', ', licenses)` |
| `classification_types` | `implode(', ', classificationTypes)` |
| `top_geos` | `topGeos.countries` **concatenated with** `topGeos.markets`, comma-joined |
| `offers_count` | `count(offers)` — **not** `offersCount`, which is only used for the warning below |
| `trackers_count` | sum of `count(offer.trackers)` across all offers |
| `local_logo_url` | local path after sync-time logo download, else `NULL` |
| `data` | the whole brand object, verbatim |

Operational warning worth keeping: if `topGeos` is empty **and** `offersCount > 0`, log
"has N offer(s) but no topGeos — check DataFlair admin". That is a data-entry error upstream,
and it silently removes the brand from geo-targeted lists.

### A.6 Error handling

| Status | Meaning and required response |
|---|---|
| 401 | **Two distinct causes.** API bearer rejected, *or* the web server's HTTP Basic gate rejecting you before the API is reached. The plugin distinguishes them by inspecting `WWW-Authenticate` and whether the body is HTML rather than JSON. Port this — conflating them sends operators hunting the wrong credential. |
| 403 | Token valid, lacks permission for the resource. Not retryable. |
| 404 | Endpoint wrong — usually a bad base URL. Not retryable. |
| 419 | Session/CSRF-shaped failure (Laravel upstream). Not retryable. |
| 429 | Rate limited. Back off; do not hammer. |
| 500 / 502 / 503 / 504 | **Retryable.** Exponential retry, max 2 attempts, then progressive split (per_page 25 → 5 → 1), then per-ID fallback. |

Error bodies carry a `message` field; surface it verbatim in the admin alongside the
plugin's own guidance text.

Other transport rules to carry across: 15 MB response cap (an unbounded upstream response is
a memory-exhaustion vector), 12 s default timeout / 20 s on the toplist list call, and the
25 s wall-clock budget with 3 s headroom checked between items.

### A.7 Known upstream gaps this plugin already works around

Port the defences, not just the happy path. Each of these is a real observed failure:

1. **`offer.geos` may be `NULL` or a non-array** — flagged in `DataIntegrityChecker` as
   "the known bug". Never assume `geos.countries` / `geos.markets` exist.
2. **`publishedAt` / `shortcode` / `slug` / `currentPeriod` missing** on some tenants. Warn,
   persist, carry on. Deliberately *not* logged to `error_log` any more because the noise
   drowned out real timing diagnostics — mirror that restraint in the .NET logger.
3. **Brand `logo` may be a bare string, a nested object (`rectangular`/`square`/`url`/`src`/`path`), or an array** — the card template walks five candidate keys before giving up. Port the whole cascade.
4. **`bonus_code` is sometimes the literal string `"N/A"`** — treat as empty, suppress the promo pill.
5. **`max_payout` absent** → the card renders `"None"`, not an empty cell.
6. **`trackerLink` occasionally arrives as an array** rather than a string; the plugin
   type-checks before use. An unvalidated affiliate URL is both a broken CTA and an
   open-redirect risk.
7. **Pagination meta can be missing** → assume one page rather than looping forever.

### A.8 What to verify against docs.dataflair.ai before Phase 2

Open questions this codebase cannot answer, listed so they can be checked in one pass:

1. Is there a `?modified_since=` delta filter yet? Plan 01 proposes adding one; if it has
   shipped, the .NET sync should use it from day one rather than full-resyncing. **This is
   the highest-value question on the list.**
2. Are webhooks available (`toplist.updated`, `brand.updated`, `alternative_toplist.updated`)?
   Plan 01's Phase C. If yes, the .NET port should expose a receiver endpoint instead of
   relying solely on a scheduled pull.
3. Published rate limits — the 429 handling is currently reactive with no documented budget.
4. Is `/api/v2` the intended path for toplists as well, and what changes in the payload?
5. Is there an official OpenAPI/Swagger document? If so, generate the .NET DTOs from it
   rather than hand-writing them from these fixtures — that removes an entire class of
   drift, and is worth a day of Phase 1 to set up.
6. Whether `alternative_toplists` has a first-class endpoint, or remains purely a local
   WordPress-side mapping (it is local-only in this plugin today).
7. Tenant/base-URL convention for Cricket World specifically, and whether their token is
   scoped to a `site.id` that filters `/toplists` automatically.
