# DataFlair Toplists — Plans Index

Living index of all parked plans for this plugin. New plans go in `to-be-implemented/`. As they're picked up, move them to `in-progress/`. As they ship, move them to `shipped/`.

## To be implemented

| # | Plan | Touches | Owner | Created | Notes |
|---|------|---------|-------|---------|-------|
| 01 | [DataFlair → WordPress Webhooks Sync Architecture](to-be-implemented/01-dataflair-to-wordpress-webhooks-sync-architecture.md) | dataflair.ai-v2 + plugin | Mex | 2026-04-25 | Phase A (delta endpoint) → B (tombstones) → C (webhooks). Coordinated cross-repo work. |
| 02 | [DataFlair Toplists for ASP.NET](to-be-implemented/02-dataflair-toplists-for-aspnet.md) | new repo `DataFlair-Toplists-Net` | Mex | 2026-08-18 | Port the plugin to custom ASP.NET CMS sites (first target: cricketworld.com). Sidecar embed first, in-process assembly second. |

## In progress

_(none)_

## Shipped

_(none yet — older plans live at the repo root, e.g. [TOPLIST_PAGE_MAP.md](TOPLIST_PAGE_MAP.md), and the global refactor roadmap is in `~/.claude/plans/you-are-the-transient-fog.md`)_

## How to use this index

1. **Adding a plan** — drop the markdown file into `to-be-implemented/`, prefix with the next number (`02-`, `03-`, …), and add a row to the table above.
2. **Starting work** — move the file to `in-progress/`, update the row.
3. **Shipping** — move the file to `shipped/`, update the row with the version + tag it landed in.
4. **Reading order** — every plan should stand alone. Don't put dependencies between plans without naming them explicitly in the doc.

## Related living plans (outside this folder)

- **`~/.claude/plans/you-are-the-transient-fog.md`** — global refactor roadmap (Phases 0A → 11). Currently at v2.1.8 / Phase 9.12. The God-class symbol-removal lands in v3.0.0.
- **[TOPLIST_PAGE_MAP.md](TOPLIST_PAGE_MAP.md)** — toplist-to-page mapping feature, parked since 2026-03-20. To be picked up on its own branch.
