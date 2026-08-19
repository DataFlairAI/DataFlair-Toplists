# DataFlair Toplists for ASP.NET — Implementation Plan

> **Companion to [plan 02](02-aspnet-toplists-port-cricket-world.md).** That document is the
> *what and why* — feature inventory, architecture, deltas, risks, decisions. This one is the
> *how*: repo scaffold, milestone task breakdown, the local test harness, and the kickoff
> prompt for the new repository.
>
> **Status:** to be implemented · **Created:** 2026-08-18 · **Owner:** Mex
> **Target repo:** `DataFlairAI/DataFlair-Toplists-AspNet` (new, empty)

---

## 1. Repository scaffold

```
DataFlair-Toplists-AspNet/
├── DataFlair.Toplists.sln
├── Directory.Build.props              shared version, langversion, nullable, SourceLink
├── README.md
├── CHANGELOG.md
├── AGENTS.md / CLAUDE.md              agent instructions for this repo
├── .github/workflows/
│   ├── build.yml                      restore → build → xUnit → pack
│   └── contract-tests.yml             replay fixtures, no network
├── docs/
│   ├── port-plan.md                   copy of plan 02 (incl. Appendix A)
│   ├── api-contract.md                Appendix A, extracted for standalone editing
│   └── runbook.md                     deploy, rollback, sync ops
├── src/
│   ├── DataFlair.Toplists.Core/           netstandard2.0 — no ASP.NET, no SQL
│   ├── DataFlair.Toplists.Data.SqlServer/ netstandard2.0 — Dapper + embedded migrations
│   ├── DataFlair.Toplists.Web/            net48 — the "plugin" surface
│   └── DataFlair.Toplists.Migrate/        net48 console — apply / --script / --verify
├── tests/
│   ├── DataFlair.Toplists.Core.Tests/     xUnit — port of the ~95 PHPUnit unit tests
│   ├── DataFlair.Toplists.Data.Tests/     xUnit — against LocalDB or a SQL container
│   ├── DataFlair.Toplists.Web.Tests/      xUnit — rendering, routing, golden HTML
│   └── fixtures/                          copied verbatim from the WP repo
└── harness/
    └── DataFlair.Harness.Web/             net48 mock host — see §4
```

`Directory.Build.props` pins one version number for every project, so the NuGet package,
the assembly, and the admin "you are on X" tile can never disagree.

---

## 2. Milestones

Each milestone ends in something demoable and independently useful. Phase numbers match
plan 02 §13; the tasks below are the level a developer can pick up directly.

### M0 — Repo bootstrap (0.5 wk)

1. Create the repo, empty, MIT or proprietary licence per DataFlair's preference.
2. Solution + four `src` projects + four `tests` projects, all building green and empty.
3. `Directory.Build.props`: `LangVersion latest`, `Nullable enable` (Core/Data — `net48`
   projects can enable it too), deterministic builds, SourceLink, symbol package.
4. CI: restore → build → test on every push. Green before any real code lands.
5. Copy in `docs/port-plan.md`, `docs/api-contract.md`, the 3 JSON fixtures, both CSS files,
   and `casino-card.php` as `docs/reference/casino-card.php.txt` (markup contract, not code).
6. `AGENTS.md`/`CLAUDE.md` stating the invariants that must never regress (§3 below).

**Exit:** `dotnet build && dotnet test` green in CI on an empty solution.

### M1 — Core + data (2 wks)

1. Models from Appendix A §A.4/§A.5. **Every offer field nullable** — field sets are
   product-type-dependent and a single tenant's data will mislead you.
2. `ISettingsStore`, `IKeyValueStore`, `IClock`, `ILogger`, repository interfaces.
3. Embedded migration scripts `001…013` reproducing the WP schema at `db_version 1.13`,
   translated to SQL Server (plan 02 §7). Persisted computed columns for `geo_type`,
   `geo_code`, `template_id`.
4. `SchemaMigrator` with **apply / --script / --verify** modes (plan 02 §8.2).
5. Dapper repositories: toplists, brands, alternatives, settings, cache, sync history.
6. `DataFlairCache` table + read-through `MemoryCache` — **not** `MemoryCache` alone.

**Exit:** migrations run clean on an empty DB, are idempotent on re-run, and `--script`
output applied by hand produces a byte-identical schema.

### M2 — Sync engine (2 wks)

1. `ApiClient`: bearer auth, 15 MB cap, 12 s / 20 s timeouts, deadline token, retry on
   500/502/503/504 (max 2), optional HTTP Basic.
2. `BaseUrlDetector` cascade — setting → cached endpoint regex → fallback.
3. Toplist sync: list-page → **re-wrap each element as `{"data": {…}}`** (Appendix A §A.3 —
   miss this and every render breaks) → persist. Progressive split 25 → 5 → 1. Per-ID
   fallback. Paginated delete on page 1, never `TRUNCATE`.
4. Brand sync: `brandStatus == "Active"` filter, the exact column derivations from §A.5,
   v1/v2 URL switch, `data.items` **or** `data.listItems`.
5. `IntegrityChecker` — port every warning, including the seven upstream gaps in §A.7.
6. Logo download → `App_Data/dataflair/logos/`, `local_logo_url` precomputed at sync.
7. Sync history + alert email. `POST /dataflair/sync/run` with a config bearer token.

**Exit:** full catalogue syncs into SQL Server; row counts and stored JSON match a
WordPress instance synced from the same account.

### M3 — Rendering + placement (3 wks)

1. Razor templates via RazorGenerator: `CasinoCard`, `ToplistTable`, `ToplistAccordion` —
   markup copied from the reference PHP, classes and SVGs unchanged. Shipped CSS uses
   `var(--df-*, <today's fixed value>)` throughout, so an instance with no overrides is
   pixel-identical to the reference — plan 02 §8.10.
2. View-models, `ProsConsResolver`, `SyncLabelFormatter`, `ReviewUrlResolver`
   (override → pattern → CTA; **no slug-variant fuzzy matching** — plan 02 §10.1).
3. **Customization system** (plan 02 §8.10 — new, not a port): `ThemeOptions` model
   (9 typed colour/preset fields, not 22 Tailwind-string fields), site-wide defaults in
   Settings, per-instance override in the placement surface, resolved into a scoped inline
   `<style>` block. Includes the star-rating colour hook that has never existed in
   WordPress. Drop `ctaMode` — dead in the source, not worth carrying over.
4. `GeoRenderGate` + `GeoFamilySelector` + `VisitorGeoResolver` (`CF-IPCountry`,
   `X-Geoip-Country`, admin `?dataflair_geo=` override). Default-deny.
5. **Cacheability signal**: the renderer reports `global` vs geo-scoped upward. Design this
   seam now — retrofitting means auditing every call site (plan 02 §11).
6. Placement surfaces: `@Html.DataFlairToplist()`, `<df:Toplist>`, and whichever of the
   three §9.2 options the block-system answer selects.
7. `/go/?campaign=` route → validate scheme → 301; 404 on miss. Tracker map in
   `DataFlairCache`, **written at sync time**, not only at render.
8. Asset handler: embedded CSS/JS, immutable headers, version-stamped URL.
9. Logos: native `loading="lazy"`, eager for positions 1–3, explicit `width`/`height`.
10. **Grid layout** (plan 02 §8.11 — new, not a port): `layout: grid` with `columns: auto|2|3`,
    a CSS-only variant of the card partial (`.dataflair-toplist--grid` modifier, no new
    template), `maxFeatures` defaulting to 2 in grid vs 3 in cards.

**Exit:** golden-HTML diff against the PHP renderer passes on all three fixtures; `/go/`
redirects correctly and survives an app-pool recycle; read-only render test passes.

### M4 — Admin (3 wks)

Dashboard, Toplists, Brands, Tools, Settings — ported from `assets/admin/*.js` with
endpoints swapped to `/dataflair/admin/api/*` and nonces to anti-forgery tokens. Behind the
host's existing auth.

New in this port: **the review-link checker** (HEADs every stored review URL, flags 404s) —
plan 02 §10.1. Manual mapping's real failure mode is silent rot, not initial setup.

**Exit:** an operator can sync, browse, edit review URLs, bulk-apply a pattern, run tests,
read logs, and change settings without touching the DB.

### M5 — Geo + cache hardening (1.5 wks)

Ship `Cache-Control: private` on geo-scoped pages plus a matching Cloudflare bypass rule.
Measure origin load. Prototype the Workers cache-key-by-country option and adopt only if the
measurement justifies it (plan 02 §11).

**Exit:** verified across the §4 geo matrix that a `country` toplist never leaks across
countries through the CDN, cold and warm.

### M6 — Hardening + handover (1.5 wks)

Security review, load test, runbook, NuGet packaging with SourceLink + symbols, version-check
tile, rollback rehearsal.

---

## 3. Invariants — put these in the repo's agent instructions

These are the behaviours that cost this codebase real incidents to learn. Each gets a test
that fails loudly:

1. **Render is read-only.** No HTTP, no writes, no media sideload, no content creation from
   any render path. (`RenderIsReadOnlyTest` equivalent.)
2. **Render query count is flat** in item count — the batched brand-meta prefetch.
3. **Geo gate default-denies.** Unresolved country never matches a country/market toplist.
4. **List elements are re-wrapped** as `{"data": {…}}` before persisting.
5. **Tracker map is durable.** Never `MemoryCache` alone.
6. **`/go/` 404s** rather than open-redirecting on an invalid stored URL.
7. **Response cap and wall-clock budget hold** under a hostile upstream.
8. **Brands with `brandStatus != "Active"` are never stored.**

---

## 4. Local test harness

> **On the question of mocking cricketworld.com:** yes, build a harness — but reproduce the
> **stack**, not the branding. Their look-and-feel teaches us nothing technically, and a page
> that reproduces a real publisher's identity is not something to put anywhere shareable.
> What actually needs reproducing is the runtime: .NET Framework 4.8, WebForms *and* MVC5 in
> one host, a block-based CMS, SQL Server, and Cloudflare-shaped request headers.

`harness/DataFlair.Harness.Web` — an ASP.NET Framework 4.8 app that stands in for the CMS:

| Harness feature | What it lets you test |
|---|---|
| `/webforms/article/{id}` — WebForms page | `<df:Toplist>` server control (plan 02 §9.1) |
| `/mvc/article/{id}` — MVC5 controller + Razor | `@Html.DataFlairToplist()` |
| Fake CMS storing articles as **ordered content blocks** in JSON | §9.2 **Option 1** — the block-type path, the one we hope is real |
| One article whose body is raw HTML with a `[dataflair_toplist id="…"]` token | §9.2 Options 2 and 3 — content hook and `Response.Filter` |
| Dev-only handler mapping `?_cf_country=IN` → `CF-IPCountry` header | The whole geo matrix with no VPN |
| **Fake DataFlair API** replaying `tests/fixtures/*.json` | Offline dev, deterministic CI, and a place to inject the §A.7 malformations |
| Bootstrap + jQuery 3.7 + a GTM stub | Collisions with the real page context |
| One page with a toplist above the fold, one below | LCP/CLS measurement for the §8.6 lazy-loading decision |
| SQL Server via LocalDB or `mcr.microsoft.com/mssql/server` container | Migrations, repositories, and the recycle test below |

Two harness tests worth writing early because they catch the expensive bugs:

- **App-pool recycle test.** Render a card, recycle the pool, then click the `/go/` link.
  Must still redirect. This is the §10.3 failure mode, and it is invisible in normal testing.
- **Fault injection from §A.7.** Feed the fake API a null `offer.geos`, an array-typed
  `trackerLink`, a `"N/A"` bonus code, a string-valued `logo`, and a response with no
  pagination meta. Nothing may throw; the card must degrade gracefully.

The harness is dev-only and must never ship in the NuGet package.

---

## 5. Kickoff prompt for the new repo

Copy the block below into a fresh Claude Code session opened in the new empty
`DataFlair-Toplists-AspNet` repository. It assumes plan 02 and this plan have been copied
into that repo's `docs/`.

```text
This repo is a greenfield .NET port of the DataFlair Toplists WordPress plugin, for
publishers running custom CMSes on ASP.NET Framework 4.x. The first target is a cricket
news site on .NET Framework 4.x behind Cloudflare, running both WebForms and MVC5.

Read docs/port-plan.md and docs/aspnet-implementation-plan.md first. They are the spec.
docs/api-contract.md (Appendix A of the port plan) is the DataFlair API contract, reverse-
engineered from the working WordPress integration — treat it as authoritative on observed
behaviour and provisional on intent.

Build order is the milestones in the implementation plan. Start at M0 and stop at its exit
criteria; do not run ahead into M1.

Key context:
- Deliverable is a drop-in class library: one DLL plus a web.config block, self-registering
  via [assembly: PreApplicationStartMethod]. No changes to the host CMS's source.
- Core and Data target netstandard2.0 so they survive a future move off .NET Framework.
  Only the Web project is framework-bound. Dapper, not EF. Precompiled Razor via
  RazorGenerator, not runtime views.
- Server-side rendering is non-negotiable — these are affiliate comparison tables and
  client-side rendering forfeits the rankings the pages exist to win.
- The reference PHP markup is in docs/reference/casino-card.php.txt. Port the DOM verbatim:
  same class names, same SVGs. The CSS in the repo is copied unchanged from the plugin, so
  any visual difference is a bug, not a redesign.

Invariants that must never regress — each needs a test that fails loudly (see §3 of the
implementation plan): render is read-only; render query count is flat in item count; the geo
gate default-denies; list elements are re-wrapped as {"data": {…}} before persisting; the
campaign→tracker map is durable across app-pool recycles; /go/ 404s rather than
open-redirecting; response-size and wall-clock caps hold; non-Active brands are never stored.

Do not: take a dependency on Preact or Goober; adopt the Lozad data-src lazy-loading pattern
(use native loading="lazy", eager for positions 1–3); build a WP-style auto-updater; or use
MemoryCache alone for anything that must survive a recycle.

Open questions are listed in port-plan.md §15 "Still open". Do not guess at them — if a task
depends on one, say so and stop rather than picking an answer.

Start by proposing the M0 scaffold as a plan for me to approve.
```

---

## 6. Creating the repo

```bash
# 1. Create the empty repo (github.com/organizations/DataFlairAI/repositories/new,
#    or with the gh CLI if you have it locally)
gh repo create DataFlairAI/DataFlair-Toplists-AspNet --private \
  --description "DataFlair Toplists for ASP.NET — drop-in .NET library port of the WordPress plugin"

# 2. Clone it next to this repo
cd ~/Sites
git clone https://github.com/DataFlairAI/DataFlair-Toplists-AspNet.git
cd DataFlair-Toplists-AspNet

# 3. Seed the docs and reference assets from the WordPress repo
mkdir -p docs/reference tests/fixtures
cp ../DataFlair-Toplists/docs/plans/to-be-implemented/02-aspnet-toplists-port-cricket-world.md docs/port-plan.md
cp ../DataFlair-Toplists/docs/plans/to-be-implemented/03-aspnet-implementation-plan.md        docs/aspnet-implementation-plan.md
cp ../DataFlair-Toplists/views/frontend/casino-card.php  docs/reference/casino-card.php.txt
cp ../DataFlair-Toplists/assets/style.css  ../DataFlair-Toplists/assets/editor.css  docs/reference/
cp ../DataFlair-Toplists/tests/phpunit/fixtures/*.json   tests/fixtures/

git add -A && git commit -m "docs: seed port plan, API contract and reference assets"
git push -u origin main

# 4. Open Claude Code in the new repo and paste the §5 kickoff prompt
```

Split `docs/api-contract.md` out of `docs/port-plan.md` (Appendix A) once seeded — it will be
edited independently as the real API docs are reconciled against it, and it is the document
a future non-WordPress port would start from.
