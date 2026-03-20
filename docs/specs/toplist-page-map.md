# Feature Spec: Toplist → Page Map

**Status:** Planned — not started
**Branch:** TBD (separate branch from main)
**Version target:** v1.9.0

---

## The Problem

The plugin has no record of which WordPress page each toplist block lives on. At scale (100 toplists, 2000+ pages), there is no way to know which pages are affected when a toplist changes on the DataFlair platform.

```
DataFlair platform          Plugin (per tenant site)
──────────────────          ──────────────────────────────────────
Toplist #42 updated    →    ??? which pages are affected?
Toplist #71 updated    →    ???

                            /best-casinos/     [toplist #42, #71]
                            /poker-sites/      [toplist #71]
                            /sports-betting/   [toplist #88]
                            ...9,900 pages with no toplists
```

---

## Architecture

Two-sided. Plugin owns discovery and the map. DataFlair receives only the map — not all pages, only the ~100 that actually contain toplists.

```
Plugin                          DataFlair Platform
──────                          ──────────────────
Scans WP post content      →    Stores map per tenant
Builds toplist→page index  →    Shows impact on toplist edit
Stores locally in DB            Notifies: "toplist #42 affects 3 pages"
Reports map on sync
Re-scans on post save
```

---

## Data Model

**New DB table: `wp_dataflair_toplist_pages`**

```sql
CREATE TABLE wp_dataflair_toplist_pages (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    toplist_id      INTEGER NOT NULL,
    post_id         INTEGER NOT NULL,
    post_title      TEXT NOT NULL,
    post_url        TEXT NOT NULL,
    last_scanned    DATETIME NOT NULL,
    UNIQUE KEY (toplist_id, post_id)
)
```

One row per toplist+page combination. A page with 3 toplist blocks = 3 rows.

---

## Discovery: How the Plugin Finds the Map

**Two triggers:**

1. **Post save** (`save_post` hook) — scan that single post immediately. Fast, surgical.
2. **Manual "Scan Now"** button in admin — full scan of all published posts/pages. Runs via WP cron to avoid timeout on large sites.

**Patterns matched:**

```php
// Gutenberg block
<!-- wp:dataflair/toplist {"toplist_id":42} /-->

// Shortcode
[dataflair_toplist id="42"]
```

Both extracted via regex from `post_content`. Only published posts scanned — drafts ignored.

---

## What Gets Reported to DataFlair

Only the map — lean payload, not all pages:

```json
{
  "site_url": "https://site1.com",
  "map": [
    { "toplist_id": 42, "pages": [
        { "url": "/best-casinos/", "title": "Best Casinos UK" },
        { "url": "/casino-reviews/", "title": "Casino Reviews" }
    ]},
    { "toplist_id": 71, "pages": [
        { "url": "/poker-sites/", "title": "Poker Sites" }
    ]}
  ]
}
```

**When reported:**
- After a full sync completes
- After a post is saved (debounced)
- On demand via "Sync Map" button

**Requires:** A `POST /api/page-map` endpoint on DataFlair side (needs to be built there).

---

## Admin UI

**New tab: "Page Map"** in the plugin settings

```
┌──────────────────────────────────────────────────────────────────────┐
│ DataFlair › Page Map                    [Scan All Pages] [Sync Map]  │
├───────────────────────────┬──────────────────────────────────────────┤
│ Toplist                   │ Pages                                    │
├───────────────────────────┼──────────────────────────────────────────┤
│ #42 Best Casinos UK       │ /best-casinos-uk/                        │
│                           │ /casino-reviews/                         │
├───────────────────────────┼──────────────────────────────────────────┤
│ #71 Poker Sites           │ /poker-sites/                            │
├───────────────────────────┼──────────────────────────────────────────┤
│ #88 Sports Betting        │ /sports-betting/                         │
├───────────────────────────┼──────────────────────────────────────────┤
│ #33 Mobile Casinos        │ ⚠ Not found on any page                  │
└───────────────────────────┴──────────────────────────────────────────┘
Last scanned: 2 minutes ago
```

- **Scan All Pages** — triggers background cron scan
- **Sync Map** — pushes current map to DataFlair API
- ⚠ warning for toplists that exist in DB but aren't placed on any page

---

## On DataFlair Side (needs building there)

When a toplist is edited on the platform, DataFlair shows:

```
⚠ This toplist appears on 3 pages across 2 sites:
   site1.com → /best-casinos-uk/, /casino-reviews/
   site2.com → /mejores-casinos/
```

Requires:
- `POST /api/page-map` endpoint to receive map from plugin
- Storage per tenant
- UI hook on toplist edit screen

---

## Edge Cases

| Case | Handling |
|---|---|
| Page deleted | `delete_post` hook removes its rows from the map table |
| Toplist removed from a page | Post save re-scans and removes stale rows |
| Same toplist twice on one page | Stored as one row (UNIQUE KEY on toplist_id + post_id) |
| 10k pages, 100 with toplists | Full scan uses `WP_Query` with `posts_per_page=50` batching via cron |
| DataFlair API unreachable | Map stored locally, sync retried on next cron tick |
| Shortcode AND block on same page | Both patterns matched, still one row per toplist+page |
| Multiple toplists on one page | One row per toplist, all linked to same post_id |

---

## Implementation Breakdown

| # | What | Where |
|---|---|---|
| 1 | DB table `wp_dataflair_toplist_pages` | `activate()` + `ensure_tables_exist()` |
| 2 | `scan_post_for_toplists($post_id)` | New method, hooked to `save_post` |
| 3 | `scan_all_posts()` | Batched cron job, triggered by "Scan All" button |
| 4 | `ajax_scan_all_pages()` | Kicks off the cron, returns job started confirmation |
| 5 | `report_map_to_dataflair()` | Pushes map to DataFlair API after sync |
| 6 | Page Map admin tab | New tab in plugin settings page |
| 7 | `delete_post` hook | Cleans up map rows when page is deleted |
| 8 | Schema bump to v1.9 | `check_database_upgrade()` |

---

## Out of Scope (this version)

- DataFlair platform UI (separate build on their side)
- Review CPT feature (separate spec)
- Mapping shortcodes in widget areas or custom fields
- Per-page SEO impact score
