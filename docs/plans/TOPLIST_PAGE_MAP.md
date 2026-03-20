# Plan: Toplist → Page Map

> Status: Parked — implement on a separate branch after v1.8.x
> Created: 2026-03-20

---

## Problem

The plugin has no record of which WordPress pages contain which toplists. At scale (2,000 pages, 100 with toplists) there is no way to know what pages are affected when a toplist changes on the DataFlair platform.

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

**Goal:** Content and SEO teams can instantly see which page(s) are affected by any toplist change. At 100 toplists across 2,000 pages, this becomes critical for operational control.

---

## Architecture

Two-sided. Plugin owns discovery and map. DataFlair receives only the map (not all pages — only the ~100 pages that contain toplists).

```
Plugin                          DataFlair Platform
──────                          ──────────────────
Scans WP post content      →    Stores map per tenant
Builds toplist→page index  →    Shows impact on toplist edit
Stores locally in DB            Notifies: "Toplist #42 affects 3 pages"
Reports map on brand sync
Re-scans on post save
```

---

## Data Model

**New DB table: `wp_dataflair_toplist_pages`**

```sql
CREATE TABLE wp_dataflair_toplist_pages (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    toplist_id      BIGINT UNSIGNED NOT NULL,
    post_id         BIGINT UNSIGNED NOT NULL,
    post_title      TEXT NOT NULL,
    post_url        TEXT NOT NULL,
    last_scanned    DATETIME NOT NULL,
    UNIQUE KEY toplist_post (toplist_id, post_id)
);
```

One row per toplist+page combination. A page with 3 toplist blocks = 3 rows.

---

## Discovery: How the Plugin Finds the Map

**Two scan triggers:**

1. **`save_post` hook** — scans that single post immediately on save. Fast and surgical.
2. **Manual "Scan All Pages" button** — full scan of all published posts/pages, batched via WP cron to avoid timeouts on large sites.

**Patterns detected:**

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
    {
      "toplist_id": 42,
      "pages": [
        { "url": "/best-casinos/", "title": "Best Casinos UK" },
        { "url": "/casino-reviews/", "title": "Casino Reviews" }
      ]
    },
    {
      "toplist_id": 71,
      "pages": [
        { "url": "/poker-sites/", "title": "Poker Sites" }
      ]
    }
  ]
}
```

**When reported:**
- After a full brand sync completes
- After a post save (debounced)
- On demand via "Sync Map" button in admin

**Requires on DataFlair side:** `POST /api/page-map` endpoint (to be built on the platform).

---

## Admin UI

**New tab: "Page Map"** in DataFlair plugin settings

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
- ⚠ Warning for toplists that exist in DB but are not used on any page

---

## DataFlair Platform Side (separate build)

When a toplist is edited on the platform, show:

```
⚠ This toplist appears on 3 pages across 2 sites:
   site1.com → /best-casinos-uk/, /casino-reviews/
   site2.com → /mejores-casinos/
```

Requires:
- `POST /api/page-map` endpoint to receive the map from each plugin instance
- Storage per tenant
- UI hook on toplist edit screen

---

## Edge Cases

| Case | Handling |
|---|---|
| Page deleted | `delete_post` hook removes its rows from the map table |
| Toplist removed from page | Post save re-scans, removes stale rows for that post |
| Same toplist twice on one page | One row (UNIQUE KEY on toplist_id + post_id) |
| 10k pages, 100 with toplists | `WP_Query` with `posts_per_page=50` batching via cron |
| DataFlair API unreachable | Map stored locally, sync retried on next cron tick |
| Shortcode AND block on same page | Both patterns matched, deduplicated to one row |
| Multiple toplists on one page | One row per toplist, all recorded |

---

## Implementation Checklist

- [ ] DB table `wp_dataflair_toplist_pages` in `activate()` + `ensure_tables_exist()`
- [ ] `scan_post_for_toplists($post_id)` — hooked to `save_post`
- [ ] `scan_all_posts()` — batched cron job
- [ ] `ajax_scan_all_pages()` — kicks off cron, AJAX response
- [ ] `report_map_to_dataflair()` — called after brand sync
- [ ] Page Map admin tab UI
- [ ] `delete_post` hook — cleans map rows
- [ ] Schema bump to v1.9 in `check_database_upgrade()`
- [ ] Tests for scan, map, reporting, edge cases

---

## Out of Scope for This Feature

- DataFlair platform UI (separate build)
- Review CPT (separate plan)
- Scanning shortcodes inside widget areas or custom fields (v2)
