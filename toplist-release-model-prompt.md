# Prompt: Toplist Stable Identity + Version Model (Phase 1)

> Paste this into Claude Code. Start in plan mode.

---

## Context

Read these files first, in this order:

1. `CLAUDE.md` (root) — project rules, module lens, role lens, tech stack, all conventions
2. `docs/knowledge/Module-Explanations.md` — full module business logic
3. `docs/plans/tobeimplemented/ToplistReleaseModel/README.md` — **the full product analysis and architectural decision document** (covers the business journey, why the current model is flawed, the recommended product model, data model, UX flow, WordPress plugin integration, risks, and phased plan). This is the source of truth for WHY we are making these changes. Read it carefully before planning.
4. `app/Models/Tenant/ListManager/TopList.php` — current toplist model
5. `app/Models/Tenant/ListManager/TopListItem.php` — current items model
6. `app/Models/Tenant/ListManager/ListTemplate.php` — current template model (being renamed)
7. `app/Http/Controllers/Tenant/ListManager/TopListController.php` — toplist web controller
8. `app/Http/Controllers/Tenant/API/V1/TopListApiController.php` — public API controller
9. `app/Http/Resources/API/V1/TopListApiResource.php` — API response shape
10. `app/Http/Resources/API/V1/TopListItemApiResource.php` — API item response
11. `database/migrations/tenant/2025_06_11_074549_create_toplists_table.php`
12. `database/migrations/tenant/2025_06_11_074626_create_toplist_items_table.php`
13. `database/migrations/tenant/2026_02_20_120000_add_sales_governance_columns_to_toplists_table.php`
14. `database/migrations/tenant/2026_02_20_130000_add_row_detail_columns_to_toplists_and_templates.php`
15. `database/migrations/tenant/2026_02_27_015559_create_toplist_order_logs_table.php`
16. `database/migrations/tenant/2026_03_02_000001_add_tracking_link_to_toplist_items.php`
17. `resources/js/Pages/Tenant/list-manager/toplists/Create.vue` — creation wizard
18. `resources/js/Pages/Tenant/list-manager/toplists/Edit.vue` — editor
19. `resources/js/Pages/Tenant/list-manager/toplists/Index.vue` — list page
20. `routes/tenant/features.php` — toplist routes
21. `routes/api-v1.php` — API routes

Also check `WORK-LOG.md` for current sprint status and `docs/plans/` for any existing toplist specs.

> **Important:** File #3 (the product analysis README) is the architectural decision document. It contains the rationale for every design choice in this prompt — the business journey, why the current model fails, the recommended product model, data model, UX flow, WordPress plugin integration plan, risks, and phased plan. If anything in this prompt seems unclear, that document has the full explanation. Do not deviate from the decisions documented there without flagging it for review.

---

## The Problem We Are Solving

The toplist system has a fundamental product flaw: toplists are flat, standalone objects. When a user creates monthly variants (e.g., "Brazil June" and "Brazil July"), each variant gets a new ID. WordPress shortcodes reference toplist IDs. So every month, someone must manually re-point every WordPress embed to the new ID. This is exactly the manual work DataFlair is supposed to eliminate.

The fix: introduce a **stable toplist parent identity** with **monthly versions** underneath. The toplist ID and slug become permanent. Monthly changes happen as versions. The API always resolves to the currently live version. WordPress embeds never break.

---

## Terminology

Use these terms consistently everywhere — code, UI, docs:

| Concept | Term | NOT this |
|---|---|---|
| Monthly plan for a toplist | **Version** | Release, snapshot, revision |
| Version that is currently published | **Live** | Active, published, current |
| Version being prepared | **Draft** | Pending, upcoming |
| Version that was replaced | **Archived** | Expired, superseded, old |
| The toplist ID/slug that never changes | **Stable identity** | Parent, master |

---

## What to Build (Phase 1 — Foundation)

### 1. New table: `toplist_versions`

Create a migration for `toplist_versions`:

```
id                  bigint PK
toplist_id          FK to toplists (cascade delete)
period              string (e.g., "2026-04", "evergreen") — unique combo with toplist_id
label               string nullable — optional name like "April Launch"
status              enum: draft / scheduled / live / archived
scheduled_at        timestamp nullable — when this version should auto-go-live
activated_at        timestamp nullable — when it actually went live
archived_at         timestamp nullable — when it was replaced
change_token        string — internal change-tracking timestamp (YmdHis pattern)
tracking_ready      boolean default false
offers_ready        boolean default false
content_ready       boolean default false
publish_ready       boolean default false
created_by          FK to users nullable
notes               text nullable
created_at          timestamp
updated_at          timestamp
soft deletes
```

Constraint: only one version per toplist can have `status = live` (enforce in application logic + a partial unique index if the DB supports it, otherwise enforce in Action classes).

### 2. Modify `toplist_items` table

Add migration:
- Add `version_id` column (FK to toplist_versions, nullable initially for migration)
- Add `slot_type` enum column: `commercial` / `editorial` / `open` (default `editorial`)
- Keep `toplist_id` for now (will be removed in a future migration after data migration is confirmed)

### 3. Add `active_version_id` to `toplists` table

Add migration:
- Add `active_version_id` column (FK to toplist_versions, nullable)
- This is a denormalized pointer for fast API resolution

### 4. Data migration

Create a migration that:
1. For each existing toplist, creates one `toplist_versions` record:
   - `toplist_id` = the toplist's ID
   - `period` = "evergreen" (existing toplists don't have month context)
   - `status` = if toplist is "live" then "live", if "draft" then "draft", if "archived" then "archived"
   - `activated_at` = toplist's `published_at` (if live)
   - `change_token` = toplist's current `version`
   - `created_by` = toplist's `owner_id`
2. Updates all `toplist_items` to set `version_id` = the newly created version for their toplist
3. Sets `slot_type` = "commercial" for items where `is_locked = true AND deal_id IS NOT NULL`
4. Sets `active_version_id` on each toplist to its newly created version (if the version is "live")

After migration, make `version_id` non-nullable on `toplist_items`.

### 5. New model: `TopListVersion`

Create `app/Models/Tenant/ListManager/TopListVersion.php`:
- Belongs to `TopList`
- Has many `TopListItem`
- Belongs to `User` (created_by)
- Scopes: `live()`, `draft()`, `scheduled()`, `archived()`
- Boot method: auto-generate `change_token` on creating (same YmdHis pattern as TopList's version field)
- Method: `goLive()` — sets status to live, activated_at to now, archives the previously live version for the same toplist, updates the parent toplist's `active_version_id`
- Method: `archive()` — sets status to archived, archived_at to now

### 6. Update `TopList` model

- Add relationship: `hasMany(TopListVersion::class)` named `versions`
- Add relationship: `belongsTo(TopListVersion::class, 'active_version_id')` named `activeVersion`
- Add relationship: `hasManyThrough(TopListItem::class, TopListVersion::class)` named `allItems`
- Update the `items` relationship to go through the active version (or keep both — `items` via active version for convenience, `allItems` via all versions)
- The toplist's `status` field semantics change: "live" means the toplist has a live version, "draft" means no version is live, "archived" means retired

### 7. Update `TopListItem` model

- Add `belongsTo(TopListVersion::class, 'version_id')` relationship named `version`
- Keep `belongsTo(TopList::class)` for backward compat during transition
- Add `slot_type` to fillable and casts (enum or string)

### 8. Update API controller and resources

**`TopListApiController::show($id)`:**
- Resolve toplist by ID (keep current behavior)
- But load items through `active_version_id` → `toplist_versions` → `toplist_items`
- If no live version exists, return 404 (same as current "not live" behavior)

**`TopListApiController::index()`:**
- Filter to toplists that have a live version (replaces the current `status = live` filter)

**`TopListApiResource`:**
- Keep the external response shape identical
- Items come from the live version, but the consumer sees them as toplist items (transparent)
- Add optional `version` object to the response:
  ```json
  "version": {
    "period": "2026-04",
    "activatedAt": "2026-04-01T00:00:00Z"
  }
  ```

**Critical: The API response shape for items must remain backward-compatible.** The WordPress plugin must continue working without changes.

### 9. Update web controller

**`TopListController::store()`:**
- When creating a toplist, also create a default version (period = "evergreen", status = "draft")
- Assign items to the version, not directly to the toplist

**`TopListController::update()`:**
- Updates operate on the active version's items (or a specified version)

**`TopListController::promoteToLive()`:**
- Refactor to use `TopListVersion::goLive()`
- Sets the version live, archives previous, updates `toplist.active_version_id`

**`TopListController::unpublish()`:**
- Archives the live version
- Sets `toplist.active_version_id` to null

**New endpoints:**
- `POST /list-manager/toplists/{toplist}/versions` — create a new version (with optional copy-from-live)
- `GET /list-manager/toplists/{toplist}/versions` — list all versions for a toplist
- `PATCH /list-manager/toplists/{toplist}/versions/{version}` — update version metadata
- `POST /list-manager/toplists/{toplist}/versions/{version}/go-live` — make a version live
- `POST /list-manager/toplists/{toplist}/versions/{version}/archive` — archive a version

### 10. Update Vue frontend (minimal for Phase 1)

**Edit.vue:**
- Show which version is being edited (period + status badge)
- Add a version tab strip or dropdown: show all versions for this toplist (month tabs)
- "New Version" button → creates a draft version (optionally copying from live)
- "Go Live" button on draft versions → replaces promoteToLive

**Index.vue:**
- The "Live" tab filters toplists with a live version
- The "Draft" tab filters toplists where all versions are draft
- Add a column or badge showing the live version's period

**Create.vue:**
- Toplist creation flow stays the same
- Behind the scenes, items are assigned to the auto-created default version

### 11. Rename ListTemplate to TargetingProfile

This is a separate, lighter task but should be done in this phase:

- Rename model: `ListTemplate` → `TargetingProfile` (or keep the class name but rename user-facing strings)
- At minimum: rename all user-facing labels in Vue pages from "Template" to "Targeting Profile" or "Brand Filter"
- Rename the nav item from "Templates" to "Targeting Profiles"
- Make template selection **optional** in the toplist creation wizard — add a "Skip — select brands manually" option in Step 1
- The database table can keep its name (`list_templates`) for now to avoid a massive migration — just rename the UI labels

### 12. Policy updates

- Add `TopListVersion` policy: `viewAny`, `view`, `create`, `update`, `goLive`, `archive`, `delete`
- Gate version go-live behind `toplist:publish` permission (same as current publish)
- Gate version creation behind `toplist:create` permission

### 13. Tests

Follow the project's TDD approach and testing playbook:

**Security tests** (`tests/Feature/Security/Modules/`):
- Cross-tenant isolation for versions (tenant A cannot access tenant B's versions)
- Wrong-role denial tests for version go-live

**Feature tests** (`tests/Feature/Tenant/ListManager/`):
- Version CRUD (create, update, delete)
- Version go-live (only one live at a time, previous auto-archives)
- Data migration correctness (existing toplists have versions, items migrated)
- API backward compatibility (response shape unchanged for existing consumers)
- Toplist creation auto-creates a default version

**Validation tests:**
- Period format validation
- Cannot have two live versions for the same toplist
- Cannot delete a live version

---

## What NOT to Build Yet (Future Phases)

- Scheduled auto-activation (cron job) — Phase 4
- Sales Inventory dashboard (open slots across toplists) — Phase 2
- WordPress plugin page scanning — Phase 3
- Webhook cache invalidation — Phase 3
- Auto-readiness flag computation — Phase 4
- Batch version activation — Phase 4
- Slug-based API lookup — Phase 3

---

## Architecture Constraints (from CLAUDE.md)

- TDD-first: write failing tests before implementation
- Multi-tenant isolation: versions are tenant-scoped, same security boundary as toplists
- Policies on every route, Form Requests on every write
- Thin controllers: use Actions for business logic (CreateVersion, GoLiveVersion, ArchiveVersion)
- Domain structure: `app/Domain/ListManager/Actions/`, `app/Domain/ListManager/Queries/`
- CRUD components: use CrudStickyBar, CrudFormSection, etc. for any new forms
- Semantic CSS: `toplist-versions`, `toplist-version__header`, etc.
- After implementation: run `/document-feature` to create docs

---

## Migration Safety

- All existing toplist IDs must be preserved — they are referenced by WordPress shortcodes
- The data migration must be idempotent (safe to run multiple times)
- The API response shape must remain backward-compatible
- The WordPress plugin must continue working without any plugin-side changes
- Keep `toplist_items.toplist_id` during transition (remove in a future cleanup migration)

---

## Success Criteria

1. Every existing toplist has exactly one version after migration
2. The API serves items through the live version, but the response shape is identical to current
3. New toplists auto-create a default version
4. Users can create additional versions (with copy-from-live)
5. Making a version live auto-archives the previous one
6. WordPress embeds continue working without changes
7. Template selection is optional in the creation wizard
8. All new endpoints have policies, form requests, and tests
9. Cross-tenant isolation tests pass for versions
