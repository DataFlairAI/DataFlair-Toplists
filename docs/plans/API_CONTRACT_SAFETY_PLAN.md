# API Contract Safety Plan — Plugin ↔ DataFlair Platform

> Branch: `claude/api-versioning-plugin-1429b6` · Drafted 2026-08-28
> Trigger: Sigma tenant developer's request for API versioning guarantees after the
> 2.2.11 block-registration fatal (plugin-side, fixed in 2.2.12) surfaced their real
> worry: backend deploys changing the API contract under a live site.

## Goal

Backend deploys can never silently corrupt a tenant site. Contract mismatches fail
loudly with a clear admin message while the site keeps serving the last good data.
Every existing plugin install (2.2.x and older) keeps working unchanged, and every
older backend keeps working with the new plugin. All handshake pieces are fallbacks:
absent on either side means today's behavior, byte for byte.

## What exists today (real, verified 2026-08-28)

- Backend: `/api/v1` (frozen: toplists, brands, tracking) and `/api/v2` (additive
  multi-vertical schema) live side by side (`routes/api-v1.php`, `routes/api-v2.php`).
  Contract tests in `tests/Feature/Tenant/API/V1|V2/` (two stale `page_type` fixtures
  being fixed in a separate task).
- Plugin: pins `/api/v1` via `ApiBaseUrlDetector` (fallback `https://sigma.dataflair.ai/api/v1`);
  brands v2 is opt-in via `dataflair_brands_api_version`. Front end renders only from
  local `wp_dataflair_*` tables; a failed sync keeps last good data. Sync aborts on
  WP_Error / non-200 / bad JSON / missing `data.id` (`ToplistFetcher`).
  `DataIntegrityChecker` records warnings into `sync_warnings` (non-blocking).
  Admin self-tests exist (`TestsRunner`: api_connection, db_schema, last_sync, …)
  plus `GET /wp-json/dataflair/v1/health` (admin-only).
- ApiClient sends only `Accept: application/json` + auth. No version identity.

Failure taxonomy (from field-level audit of `views/frontend/casino-card.php` +
`src/Frontend/Render/`, ~60 consumed fields):

- **Bucket 1 — silent drift**: renames/retypes/enum changes inside `data.items`
  (e.g. `offerText`, `bonus_code`, `trackers[].trackerLink`, `rating` type,
  `productType` values, `publishedAt` format, pagination meta). Pass sync gates,
  degrade pages silently. **This is the whole remaining problem.**
- **Bucket 2 — loud abort**: envelope/route/auth changes. Already fail safe today.

## Plan

### Backend (dataflair.ai-v2)

**B1 — Meta endpoint** *(proposed — does not exist today)*
`GET /api/v1/meta` and `GET /api/v2/meta`, no tenant data, lightly rate-limited:

```json
{
  "api_version": "v1",
  "contract_rev": "1.0.0",
  "min_plugin_version": "2.0.0",
  "latest_plugin_version": "2.3.0",
  "deprecations": []
}
```

`contract_rev` is semver per API version: patch/minor for additive changes only;
breaking changes are forbidden within a version (they go to the next `/api/vN`).

**B2 — Version-aware middleware** *(proposed)*
Reads `X-DataFlair-Plugin-Version` + `X-DataFlair-Expected-Contract` request headers.
Mismatch (expected contract unsupported, or plugin below min version) → HTTP 409
`{"error_code":"contract_mismatch","message":…,"min_plugin_version":…}`.
**Fallback rule: no headers → middleware is a strict no-op.** Every existing install
keeps today's behavior. Side benefit: log plugin version per tenant for a
who-runs-what view.

**B3 — Contract lock in CI**
Fix the two stale `page_type` fixtures (in progress). Add a v1 key-set snapshot test:
assert the exact set of keys in a fully-populated toplist item response so a rename
or removal fails CI, not just a value check. Policy: `app/Http/Resources/API/V1/*`
is append-only.

**B4 — Release process** (the developer's two smaller asks)
Staging deploy + one plugin sync against staging before production release. Semver
on platform releases. One "API contract" line in each release's notes (even "no
changes"). No new tooling; just discipline.

### Plugin (this repo)

**P1 — Identity headers** *(proposed)*
`ApiClient` adds `X-DataFlair-Plugin-Version: DATAFLAIR_VERSION` and
`X-DataFlair-Expected-Contract: v1` (v2 for the brands path when opted in).
Unknown headers are ignored by old backends — fallback-safe by construction.

**P2 — Loud 409 handling** *(proposed)*
On 409 `contract_mismatch`: abort sync before any write, persistent admin notice,
sync-history entry. Notice copy (no em-dashes in UI copy):
"DataFlair sync paused: this plugin version (2.2.x) is no longer compatible with the
DataFlair API. Your site continues to show the last synced data. Please update the
plugin to version X.Y or newer."

**P3 — Pre-sync canary check** *(proposed)*
Before a full sync: (a) `GET /meta` — on 404/error, skip the handshake entirely and
proceed as today (old-backend fallback); (b) fetch ONE toplist and run
`DataIntegrityChecker` with a new hard/soft classification:
- **hard** (abort whole sync before any write + admin notice): `items` not an array,
  item missing `offer` object or `brand` linkage, `trackers[].trackerLink` absent
  when trackers exist, top-level `id/name/items` missing.
- **soft** (proceed; recorded in `sync_warnings` as today): everything else.
Escape hatch: `dataflair_strict_contract_check` filter, default true; hard list kept
deliberately minimal so real-world partial data never false-positives.

**P4 — Render hardening audit** *(proposed, defense in depth)*
Audit the ~60 consumed fields for reads that would emit notices/fatals on a type
change (non-array `trackers`, non-scalar `rating`, …) and guard them. Worst case on
bad data becomes a missing element, never on-page errors under WP_DEBUG. Targeted
edits only; no template rewrite.

**P5 — Visibility** *(proposed)*
New `contract_check` entry in the existing `TestsRunner` registry (runs P3 canary on
demand, shows PASS/FAIL + drifted fields). Health endpoint gains
`contract_status` + `last_contract_check`. Dashboard badge when the last sync had
hard failures.

**P6 — Compatibility panel on Settings** *(proposed, optional, later)*
Plugin version, pinned contract, server `contract_rev` / `min_plugin_version` from
`/meta`, last check time. Not required for safety; skip unless wanted.

## Fallback matrix (backward compatibility guarantee)

| Plugin | Backend | Behavior |
|---|---|---|
| old (≤2.2.x) | new | No headers sent → middleware no-op; `/meta` unused. Identical to today. |
| new | old (no B1/B2) | `/meta` 404 → handshake skipped; no 409s exist. Identical to today. |
| new | new | Full handshake: loud mismatch, canary gate, visibility. |

Rollout order: backend B1–B3 first (purely additive), then plugin release. Neither
side ever depends on the other having shipped.

## Explicitly not doing (YAGNI)

Full JSON-schema validation of payloads (reuse `DataIntegrityChecker`), Sunset/RFC
8594 deprecation headers, machine-readable changelog feeds, auto-switching the
plugin to newer API versions, a separate compatibility micro-service.

## Test strategy

- Plugin unit: header injection; 409 → abort + notice; canary hard/soft
  classification table; `/meta` 404 fallback proceeds unchanged.
- Plugin integration: Bucket-1 payload (renamed `offerText`, non-array `trackers`)
  → hard abort **before** persist; DB still holds previous good rows (round-trip
  rule); existing 720 tests stay green.
- Backend feature: `/meta` shape + rate limit + no tenant data; middleware no-op
  without headers; 409 shape with mismatched headers; v1 key-set snapshot test.
- Security: meta endpoint leaks nothing tenant-specific; headers can't be used to
  bypass auth/scopes; 409 body contains no internals.

## Acceptance criteria (abridged)

- Given an old plugin and the new backend, when it syncs, then responses and
  behavior are byte-identical to today.
- Given the new plugin and an old backend, when `/meta` 404s, then sync proceeds
  exactly as today with no notice.
- Given a v1 response where `offerText` was renamed, when sync runs, then no row is
  written, the previous data still renders, and an admin notice names the drifted
  field.
- Given a backend that requires plugin ≥ X and a plugin < X, when it syncs, then the
  sync aborts with the update notice and the site serves last good data.

## Sequencing

1. B3 fixture fix (running as background task).
2. B1 + B2 backend (small).
3. P1 + P2 plugin (small) → release as 2.3.0.
4. P3 + P5 (medium).
5. P4 audit (small).
6. B4 process note + reply to the Sigma developer.
7. P6 only if wanted.

## Message to the Sigma developer (summary)

v1/v2 separation already exists and is policy: additive-only within a version,
breaking changes go to a new version. His pages render from a local database and a
failed sync keeps the last good data, so a backend change cannot take the site down;
the fatal he hit was plugin-side and is fixed in 2.2.12. Committing to staging
tests, semver, per-release API changelog, and shipping the explicit
version-handshake (loud mismatch errors) in plugin 2.3.0.
