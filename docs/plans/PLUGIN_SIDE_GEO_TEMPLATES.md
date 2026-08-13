# Plan: Plugin-Side Geo Templates

> Status: Parked — explicitly deferred (2026-08-13 conversation). Not being built now; "keep it simple" for the current release.
> Created: 2026-08-13

---

## Problem

`auto_geo="true" template="X"` (shipped in v2.2.3) lets one shortcode/block auto-pick the right regional toplist variant from a "family" — but the family grouping (`list_template_id`) is defined entirely on the DataFlair backend, by whoever created each toplist picking the same Template dropdown value. There is no UI for this grouping concept in the WordPress plugin at all, and the site owner doesn't use/have a workflow around it on the DataFlair backend either — so in practice `auto_geo` is currently unusable for this account.

Even where it *is* set up, the backend-side grouping has a workflow gap: if a publisher wants to stop targeting a market (say, pull Germany from a template), they have to know that template exists conceptually, go find every toplist tagged with it, and there's no propagation — the grouping is just an implicit shared foreign key on individual DataFlair rows, not a first-class managed thing anywhere.

## Proposed Idea

Move template/family definition into the **plugin itself**, decoupled from whatever grouping (if any) exists on the DataFlair backend:

- A new WP-side concept: a **Geo Template** — a named mapping of `{region → toplist}`, e.g. "Casino Comparison" → `{GB: toplist #14, DE: toplist #9, global: toplist #16}`.
- Defined and edited in WP admin (DataFlair → Geo Templates), not on the DataFlair platform.
- A page references the template once (`template="casino-comparison"` or similar), and the *region → toplist* mapping is resolved from the plugin's own table at render time — same visitor-matching logic as today's `GeoFamilySelector`, just sourced from a WP-owned mapping instead of `list_template_id_virtual` synced from the API.

**The core benefit (stated directly by the user):** change the mapping once, in one place, and every page using that template updates automatically. E.g. stop running a German offer → edit the Geo Template, drop the `DE` entry → every page referencing that template stops showing German-targeted content, with zero per-page edits.

```
┌─ DataFlair → Geo Templates ─────────────────────────────────────┐
│                                                    [+ New Template] │
├───────────────────────────────────────────────────────────────────┤
│ Casino Comparison                                    [Edit] [Del] │
│   GB      → Best UK Casino Brands 2026 (#14)                      │
│   Global  → Best UK Casino Brands 2026 (Global Test) (#16)        │
│                                                                     │
│ Malta Market                                          [Edit] [Del] │
│   Market: Malta → Casino, CricketWorld (Malta) (#8)                │
└───────────────────────────────────────────────────────────────────┘
```

## Open Questions (unresolved — needs real design pass before building)

- Does this *replace* `auto_geo`+`template=<DataFlair template id>`, or coexist as a second, WP-owned template namespace? (Likely replace, to avoid two competing grouping concepts.)
- How does a WP admin pick "which toplist represents Germany" — a dropdown of all synced toplists, filtered by nothing in particular (any toplist could be assigned to any region here, decoupled from its own `geo_type` on the DataFlair side)?
- Should the plugin's own `geo_type` gate (`GeoRenderGate`, Layer 1) still apply on top of a plugin-side template pick, or does the template assignment itself imply the intended region (making the backend's own `geo_type` on that toplist irrelevant for this placement)?
- Relationship to `TOPLIST_PAGE_MAP.md` (parked separately) — a Geo Template admin screen showing "used on N pages" would want the same page-scanning mechanism that plan already designs.

## Explicitly Out of Scope For Now

- Any code changes. This is a parked idea only, captured so it isn't lost — see conversation 2026-08-13.
- Deciding the exact data model / admin UI — the sketch above is illustrative, not a spec.
