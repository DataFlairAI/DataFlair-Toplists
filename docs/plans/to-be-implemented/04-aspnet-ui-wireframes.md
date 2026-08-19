# DataFlair Toplists for ASP.NET — UI Wireframes

> Companion to [plan 02](02-aspnet-toplists-port-cricket-world.md) (what/why) and
> [plan 03](03-aspnet-implementation-plan.md) (how). This one is *what it looks like*.
>
> Every screen below mirrors a screen that already exists in the WordPress plugin, so these
> are ports rather than designs. Where the ASP.NET version has to differ, it is called out.

---

## 1. Where the admin lives

WordPress gives the plugin a left sidebar slot. ASP.NET gives us nothing — so the admin
mounts as a self-contained area at a reserved path with its own top nav.

```
WordPress (today)                        ASP.NET port
─────────────────────────                ──────────────────────────────────────────
wp-admin sidebar                         https://site.com/dataflair/admin/
┌──────────────┐                         ┌────────────────────────────────────────┐
│ ⌂ Dashboard  │                         │ DataFlair                     ▸ Sync ▾ │
│ ✎ Posts      │                         ├────────────────────────────────────────┤
│ ▤ Media      │                         │ Dashboard │Toplists│Brands│Tools│Setgs │
│ ...          │                         └────────────────────────────────────────┘
│▸ DataFlair ◂ │  ← dashicons-list-view      Own shell, own top nav, Bootstrap
│  ├ Dashboard │     position 30             (already on the site) + jQuery 3.7
│  ├ Toplists  │
│  ├ Brands    │                         Behind the CMS's EXISTING auth (§2 answer 5).
│  ├ Tools     │                         Not a parallel login. Not a shared secret.
│  └ Settings  │
└──────────────┘
```

**If their CMS admin has an extension point for nav items**, add one link pointing at
`/dataflair/admin/` so it is discoverable from their existing admin rather than being a URL
people have to remember. That is a nice-to-have, not a blocker — worth asking about
alongside the block-system question.

The five screens and their routes:

| Screen | Route | WP equivalent |
|---|---|---|
| Dashboard | `/dataflair/admin/` | `?page=dataflair-toplists` |
| Toplists | `/dataflair/admin/toplists` | `?page=dataflair-toplists-list` |
| Brands | `/dataflair/admin/brands` | `?page=dataflair-brands` |
| Tools | `/dataflair/admin/tools?tab=…` | `?page=dataflair-tools` |
| Settings | `/dataflair/admin/settings?tab=…` | `?page=dataflair-settings` |

---

## 2. Dashboard

```
┌──────────────────────────────────────────────────────────────────────────────────┐
│ DataFlair                                                             ▸ Sync ▾   │
├──────────────────────────────────────────────────────────────────────────────────┤
│ ▸Dashboard│ Toplists │ Brands │ Tools │ Settings                                  │
├──────────────────────────────────────────────────────────────────────────────────┤
│                                                                                  │
│  Dashboard                              [ Sync Brands ]  [ Sync Toplists ]       │
│                                                                                  │
│  ┌────────────────┐ ┌────────────────┐ ┌────────────────┐ ┌────────────────┐     │
│  │ BRANDS SYNCED  │ │ TOPLISTS       │ │ LAST SYNC      │ │ API HEALTH     │     │
│  │                │ │                │ │                │ │                │     │
│  │      142       │ │       18       │ │  12 min ago    │ │  ● Healthy     │     │
│  │  ● ok          │ │  ● ok          │ │  next: manual  │ │  198 ms        │     │
│  └────────────────┘ └────────────────┘ └────────────────┘ └────────────────┘     │
│                                                                                  │
│  ┌──────────────────────────────────────────────┐ ┌────────────────────────────┐ │
│  │ Recent Sync Activity              View all → │ │ Sync Commands              │ │
│  ├──────────────────────────────────────────────┤ ├────────────────────────────┤ │
│  │ [ok]   Toplists — 18 synced        12 m ago  │ │ Sync toplists              │ │
│  │ [ok]   Brands — 142 synced         12 m ago  │ │  dataflair-cli sync top…   │ │
│  │ [warn] Toplists — 2 warnings        1 h ago  │ │ Sync brands                │ │
│  │ [ok]   Brands — 142 synced          6 h ago  │ │  dataflair-cli sync brands │ │
│  │ [err]  Toplists — HTTP 503         12 h ago  │ │ API health                 │ │
│  └──────────────────────────────────────────────┘ │  dataflair-cli health      │ │
│                                                   ├────────────────────────────┤ │
│  ┌──────────────────────────────────────────────┐ │ Embed Usage                │ │
│  │ Sync Console                          [clear]│ │ 34 pages use a toplist     │ │
│  ├──────────────────────────────────────────────┤ │                            │ │
│  │ 22:41:03 ▸ Fetching toplists page 1/2…       │ │ [dataflair_toplist id="3"] │ │
│  │ 22:41:05 ✓ Stored 15 toplists                │ │                   [ Copy ] │ │
│  │ 22:41:07 ▸ Fetching toplists page 2/2…       │ └────────────────────────────┘ │
│  │ 22:41:08 ✓ Stored 3 toplists — done          │                                │
│  └──────────────────────────────────────────────┘                                │
└──────────────────────────────────────────────────────────────────────────────────┘
```

**Change from WordPress:** the "WP-CLI Sync" card becomes "Sync Commands" pointing at
`DataFlair.Toplists.Migrate.exe` / a small `dataflair-cli`, and the scheduled-jobs line
reflects whatever scheduling host is chosen (still-open decision 8) rather than WP-Cron.

---

## 3. Toplists

```
┌──────────────────────────────────────────────────────────────────────────────────┐
│  Toplists                                            [ Fetch All Toplists ]      │
│                                                                                  │
│  Search: [ name or slug…          ]  Geo: [ any ▾ ]  Sort: [ name ▾ ]  [ Clear ] │
│                                                                                  │
│  ☐ 3 selected    [ Re-sync Selected ]  [ Delete Selected ]                       │
│  ┌──┬──┬──────┬───────┬──────────────────────────┬──────┬────────┬──────┬───────┐│
│  │☐ │  │ ID   │ API   │ Name                     │ Items│ Period │ Geo  │Synced ││
│  ├──┼──┼──────┼───────┼──────────────────────────┼──────┼────────┼──────┼───────┤│
│  │☑ │▸ │  12  │  55   │ Top Casinos India        │  10  │2026-08 │ IN   │ 12 m  ││
│  │☐ │▾ │  13  │  56   │ Best Sportsbooks Brazil  │   8  │2026-08 │ BR   │ 12 m  ││
│  │  │  │      │       │                          │      │        │      │       ││
│  │  │  │  ┌─────────────────────────────────────────────────────────────────┐   ││
│  │  │  │  │ ▸ Items │ Raw JSON                                              │   ││
│  │  │  │  ├─────────────────────────────────────────────────────────────────┤   ││
│  │  │  │  │  #  Brand           Offer                          Status       │   ││
│  │  │  │  │  1  Casino Alpha    100% up to R$1000 + 50 spins   [ok] locked  │   ││
│  │  │  │  │  2  Betting Beta    Bet R$50 get R$150 free bet    [ok]         │   ││
│  │  │  │  │  3  Gamma Casino    200% welcome bonus             [warn] no geo│   ││
│  │  │  │  └─────────────────────────────────────────────────────────────────┘   ││
│  │☑ │▸ │  14  │  57   │ Top Poker Rooms          │   6  │2026-08 │glob. │ 12 m  ││
│  │☑ │▸ │  15  │  58   │ Top Casinos UK           │  10  │2026-08 │ GB   │ 1 h   ││
│  └──┴──┴──────┴───────┴──────────────────────────┴──────┴────────┴──────┴───────┘│
│                                          ◂ Prev   Page 1 of 2   Next ▸           │
└──────────────────────────────────────────────────────────────────────────────────┘
```

The per-row accordion has two tabs — **Items** (position / brand / offer / status pill,
loaded on demand) and **Raw JSON** (copy + download). The Geo column is new-ish: it surfaces
the persisted computed column from §7 so an operator can see at a glance which toplists will
make a page uncacheable (§11).

---

## 4. Brands

```
┌──────────────────────────────────────────────────────────────────────────────────┐
│  Brands                                                [ Fetch All Brands ]      │
│                                                                                  │
│  Search: [ brand name…      ]  Type: [ any ▾ ]  Status: [ Active ▾ ]  [ Clear ]  │
│                                                                                  │
│  ☐ 2 selected   [ Bulk Apply Review Pattern ]  [ Disable Selected ]  [ Re-sync ] │
│  ┌──┬──┬─────────────────┬──────────┬──────┬──────┬────────────┬──────┬─────────┐│
│  │☐ │  │ Brand           │ Type     │Offers│Track.│ Top Geos   │Status│Review URL│
│  ├──┼──┼─────────────────┼──────────┼──────┼──────┼────────────┼──────┼─────────┤│
│  │☐ │▸ │ ▤ Casino Alpha  │ Casino   │  4   │  6   │ BR, LATAM  │Active│/reviews/│
│  │  │  │                 │          │      │      │            │      │casino-al│
│  │  │  │                 │          │      │      │            │      │ [Edit]  │
│  │☑ │▾ │ ▤ Betting Beta  │Sportsbook│  2   │  3   │ IN         │Active│ ⌨ ▓▓▓▓  │
│  │  │  │                                                          ↑ inline edit  │
│  │  │  │  ┌─────────────────────────────────────────────────────────────────┐   ││
│  │  │  │  │  #  Offer Text              Geo      Campaign      Affiliate URL │   ││
│  │  │  │  │  1  Bet ₹500 get ₹1500     IN       India Main    track.ex/…    │   ││
│  │  │  │  │  2  100% up to ₹10,000     IN       India Alt     track.ex/…    │   ││
│  │  │  │  └─────────────────────────────────────────────────────────────────┘   ││
│  │☑ │▸ │ ▤ Gamma Casino  │ Casino   │  3   │  4   │ GB         │Active│ — (none)│
│  └──┴──┴─────────────────┴──────────┴──────┴──────┴────────────┴──────┴─────────┘│
└──────────────────────────────────────────────────────────────────────────────────┘

  Bulk Apply Review Pattern ──────────────────────────────┐
  │  Apply to 2 selected brands.                          │
  │  Pattern must contain {slug}.                          │
  │                                                        │
  │  [ /betting/reviews/{slug}/                        ]   │
  │                                                        │
  │  Preview:  Betting Beta  → /betting/reviews/betting-beta/
  │            Gamma Casino  → /betting/reviews/gamma-casino/
  │                                                        │
  │                          [ Cancel ]  [ Apply to 2 ]    │
  └────────────────────────────────────────────────────────┘
```

**This screen carries more weight in the ASP.NET port than in WordPress.** With reviews
mapped manually (§10.1), `Review URL` is the *primary* mechanism rather than a fallback, so
the bulk-pattern tool and the link checker in Tools are what make the workflow survivable at
142 brands.

The review-URL cell keeps the WordPress behaviour: locked after save, unlocks on **Edit**.

---

## 5. Tools

```
┌──────────────────────────────────────────────────────────────────────────────────┐
│  Tools                                                                           │
│  ┌─────────────────────┬──────────┬──────────────┬──────────────────┐            │
│  │ ▸Tests & Diagnostics│   Logs   │ API Preview  │ Review Links ★new│            │
│  └─────────────────────┴──────────┴──────────────┴──────────────────┘            │
│                                                                                  │
│   12 passed · 1 warning · 0 failed                      [ Run All Tests ]        │
│                                                                                  │
│   ┌────────────────────────────────────────────────────────────────────┐         │
│   │ ● API connection reachable                    198 ms      [ Run ]  │         │
│   │ ● Schema at expected version (1.13)                       [ Run ]  │         │
│   │ ● All toplists have ≥1 item                               [ Run ]  │         │
│   │ ◐ 3 brands have offers but no topGeos         warning     [ Run ]  │         │
│   │ ● Tracker map durable across recycle                      [ Run ]  │         │
│   │ ● No toplist stale > 3 days                               [ Run ]  │         │
│   └────────────────────────────────────────────────────────────────────┘         │
└──────────────────────────────────────────────────────────────────────────────────┘

  Review Links tab (new in the ASP.NET port) ─────────────────────────────────┐
  │  Last checked: 2 hours ago                    [ Check All Review URLs ]   │
  │                                                                           │
  │   138 ok  ·  3 broken  ·  1 redirect                                      │
  │   ┌─────────────────────────────────────────────────────────────────┐     │
  │   │ ✗ 404  Gamma Casino    /betting/reviews/gamma-casino/   [ Fix ] │     │
  │   │ ✗ 404  Delta Bet       /betting/reviews/delta-bet/      [ Fix ] │     │
  │   │ ↪ 301  Epsilon Play    → /reviews/epsilon/              [ Fix ] │     │
  │   └─────────────────────────────────────────────────────────────────┘     │
  └───────────────────────────────────────────────────────────────────────────┘
```

The **Review Links** tab does not exist in WordPress. It exists here because manual mapping's
failure mode is silent rot — a review moves or unpublishes months later and nothing notices
until a reader hits a 404 on a money page.

---

## 6. Settings

```
┌──────────────────────────────────────────────────────────────────────────────────┐
│  Settings                                        ● Unsaved changes    [ Save ]   │
│  ┌────────────────┬────────────────┬──────────┬──────────────┐                   │
│  │ ▸API Connection│ Customizations │   Sync   │ Geo-Targeting│                   │
│  └────────────────┴────────────────┴──────────┴──────────────┘                   │
│                                                                                  │
│   API base URL     [ https://cricketworld.dataflair.ai/api/v1              ]     │
│   API token        [ ••••••••••••••••••••••••••••••••  ]  ⓘ from web.config     │
│   Brands API ver.  ( ) v1   (•) v2                                               │
│                                                                                  │
│   HTTP Basic (staging only, optional)                                            │
│   User [                 ]   Password [                 ]                        │
│                                                                                  │
│                                            [ Test Connection ]                   │
│   ┌────────────────────────────────────────────────────────────────────┐         │
│   │ ✓ Connected — HTTP 200 in 198 ms, 18 toplists visible              │         │
│   └────────────────────────────────────────────────────────────────────┘         │
└──────────────────────────────────────────────────────────────────────────────────┘
```

**One deliberate divergence from WordPress:** the API token is read from `web.config`
`appSettings` (encryptable with `aspnet_regiis -pe`), **not** stored in the settings table.
The field shows masked and read-only with a pointer to where it actually lives. `wp_options`
was never a good home for a bearer token either; the port just declines to repeat it.

The amber **Unsaved changes** pill and the `beforeunload` guard port from the WordPress
dirty-state script unchanged.

**The Customizations tab is rebuilt, not ported (plan 02 §8.10).** WordPress's version is
four text inputs expecting hand-typed Tailwind syntax (`"brand-600"`, `"bg-[#ff0000]"`) that
the rendered card never actually reads — set them to anything and the card stays whatever
the stylesheet says. The ASP.NET version uses real colour pickers against the 9 properties
that actually reach the page, plus one that has never existed before:

```
┌──────────────────────────────────────────────────────────────────────────────────┐
│  Settings                                                             [ Save ]   │
│  ┌────────────────┬────────────────┬──────────┬──────────────┐                   │
│  │  API Connection│▸Customizations │   Sync   │ Geo-Targeting│                   │
│  └────────────────┴────────────────┴──────────┴──────────────┘                   │
│                                                                                  │
│  Site-wide defaults — every toplist uses these unless a block/shortcode          │
│  overrides them.                                                                │
│                                                                                  │
│   Ribbon background   [🟥]  #e11d48      Ribbon label   [ Editor's Pick      ]  │
│   Ribbon text         [⬜]  #ffffff                                              │
│                                                                                  │
│   CTA button          [🟦]  #2563eb      CTA hover      [🟦]  #1d4ed8           │
│   CTA text            [⬜]  #ffffff                                              │
│                                                                                  │
│   Rank badge bg       [⬜]  #f3f4f6      Rank badge text [⬛]  #111827           │
│   Brand name link     [🟪]  #2563eb                                              │
│   Star rating ★new    [🟨]  #fbbf24      ← no hook for this existed in WordPress │
│                                                                                  │
│   Card style           ( Rounded ▾ )     Rounded · Square · Elevated             │
│                                                                                  │
│  ┌────────────────────────────────────────────────────────────────────┐         │
│  │  Live preview                                                      │         │
│  │  ┌──────────────────────────────────────────────────────────┐      │         │
│  │  │ ★ EDITOR'S PICK          ┌───┐  Casino Alpha    ★4.8/5    │      │         │
│  │  │  ┌───┐                   │Play Now →│                     │      │         │
│  │  │  │ 1 │  100% up to ₹10,000                                │      │         │
│  │  │  └───┘                                                     │      │         │
│  │  └──────────────────────────────────────────────────────────┘      │         │
│  └────────────────────────────────────────────────────────────────────┘         │
└──────────────────────────────────────────────────────────────────────────────────┘
```

The live preview matters more here than it would in WordPress: the old panel had no preview
either, which is presumably part of how 22 non-functional fields shipped unnoticed — nothing
on the settings screen ever contradicted them. A preview that actually reflects the saved
values is a cheap guard against the same failure mode recurring.

---

## 7. How a toplist gets on a page

Three mechanisms, best first. Which one is live depends on the still-open block-system
question (§9.2).

### Option 1 — CMS block (preferred, if their block system is real)

```
  Article editor                            Block picker
┌────────────────────────────────┐        ┌─────────────────────────────────────┐
│ Title: Best Betting Sites 2026 │        │  Add block                    ✕     │
├────────────────────────────────┤        ├─────────────────────────────────────┤
│ ┌────────────────────────────┐ │        │  Search [ toplist            ]      │
│ │ ▤ Text block               │ │        │                                     │
│ │ Cricket betting has grown… │ │        │  ┌────────┐ ┌────────┐ ┌────────┐   │
│ └────────────────────────────┘ │        │  │  ▤     │ │  ▦     │ │  ★     │   │
│ ┌────────────────────────────┐ │        │  │ Text   │ │ Image  │ │DataFlair│  │
│ │ ★ DataFlair Toplist        │ │        │  │        │ │        │ │ Toplist│   │
│ │ ┌────────────────────────┐ │ │        │  └────────┘ └────────┘ └────────┘   │
│ │ │ Toplist: Top Casinos   │ │ │        └─────────────────────────────────────┘
│ │ │          India      ▾  │ │ │
│ │ │ Layout:  Cards      ▾  │ │ │          Block settings panel
│ │ │ Limit:   [ 10 ]        │ │ │        ┌─────────────────────────────────────┐
│ │ │                        │ │ │        │ Toplist    [ Top Casinos India  ▾ ] │
│ │ │ ⚠ Geo-scoped (IN) —    │ │ │        │ Layout     [ Cards              ▾ ] │
│ │ │   page will not be     │ │ │        │ Limit      [ 10                   ] │
│ │ │   CDN-cached           │ │ │        │ ─────────────────────────────────── │
│ │ └────────────────────────┘ │ │        │ ▸ Pros / cons overrides             │
│ │      [ Preview ]           │ │        │ ▾ Colours        using site default │
│ └────────────────────────────┘ │        │    Ribbon     🟥   CTA button  🟦   │
│ ┌────────────────────────────┐ │        │    Rank badge ⬜   Star rating 🟨   │
│ │ ▤ Text block               │ │        │    Card style ( Rounded        ▾ )  │
│ │ Our verdict…               │ │        │    [ Reset to site default ]        │
│ └────────────────────────────┘ │        └─────────────────────────────────────┘
└────────────────────────────────┘
```

The ⚠ line is why the block path is worth fighting for: the editor learns the caching
consequence at insert time, not from a support ticket three weeks later.

The Colours panel is the rebuilt, real one (plan 02 §8.10) — actual colour swatches, a
bounded card-style choice instead of freeform text, and an explicit "using site default"
state so an editor can tell at a glance whether they're overriding anything. It replaces
what was, in WordPress, 22 Tailwind-syntax text fields that the rendered card never read —
and the "CTA mode" field is gone entirely, for the same reason.

**The `Layout ▾` field above gains a third option: `Cards | Grid | Accordion Tables
(Testing)`.** Picking `Grid` reveals one more control, `Columns: Auto (recommended) | 2 | 3`.
See §8a below for what it renders.

### Option 2 — token in the article body (content hook)

```
  Article body (raw HTML stored by the CMS)
┌──────────────────────────────────────────────────────────────────┐
│ <p>Cricket betting has grown fast in India…</p>                  │
│                                                                  │
│ [dataflair_toplist id="55" limit="10" layout="cards"]            │
│                                                                  │
│ <p>Our verdict on each of these…</p>                             │
└──────────────────────────────────────────────────────────────────┘
                              │
                              │  one line, in the CMS's content-render path:
                              │     html = DataFlairTokens.Expand(html);
                              ▼
                  fully rendered toplist HTML
```

### Option 3 — `Response.Filter` (zero host changes, last resort)

Same token, rewritten in the outgoing HTML stream by the HttpModule. Works with no CMS
cooperation at all; buffers the response, can fight IIS dynamic compression, breaks on
unbuffered responses. Ship-it fallback, not a design goal.

### Developer placement (always available, regardless of the above)

```razor
@Html.DataFlairToplist(new ToplistOptions { Id = 55, Limit = 10, Layout = "cards" })
```
```aspx
<df:Toplist runat="server" ToplistId="55" Limit="10" Layout="cards" />
```

---

## 8. The rendered card (front end)

Same DOM, same class names, same SVGs as the WordPress template — the CSS is copied
unchanged, so this must look identical.

```
   ┌─ .casino-card-ribbon (position 1 only) ─┐
   │  ★  OUR TOP CHOICE                      │
┌──┴─────────────────────────────────────────┴──────────────────────────────────┐
│ .casino-card.top-choice                                                       │
│ ┌───────────────────┬──────────────────┬─────────────────┬──────────────────┐ │
│ │ .casino-brand-col │ .casino-bonus-col│ .features-col   │ .casino-cta-col  │ │
│ │                   │                  │                 │                  │ │
│ │  ┌───┐            │ WELCOME BONUS    │ ✓ Fast payouts  │  ┌─────────────┐ │ │
│ │  │ 1 │  ┌───────┐ │                  │ ✓ 24/7 support  │  │  Play Now → │ │ │
│ │  └───┘  │ LOGO  │ │ 100% up to       │ ✓ 500+ games    │  └─────────────┘ │ │
│ │         └───────┘ │ ₹10,000          │                 │   T&Cs apply     │ │
│ │                   │                  │                 │                  │ │
│ │  Casino Alpha     │ Promo Code:      │                 │                  │ │
│ │  Our rating:      │ ┌──────────────┐ │                 │                  │ │
│ │  ★ 4.8/5          │ │ WELCOME100 ⧉ │ │                 │                  │ │
│ │  Read Review →    │ └──────────────┘ │                 │                  │ │
│ └───────────────────┴──────────────────┴─────────────────┴──────────────────┘ │
│                          ▾  More details                                      │
├───────────────────────────────────────────────────────────────────────────────┤
│ .casino-card-details   (Alpine x-data toggle — hidden until clicked)          │
│  ┌──────────┬──────────┬──────────┬──────────┐                                │
│  │ Wagering │ Min dep. │ Max pay. │ Payout   │   ← .casino-metrics-grid       │
│  │   30x    │  ₹500    │  None    │  1-3 d   │                                │
│  └──────────┴──────────┴──────────┴──────────┘                                │
│  Pros                          Cons                                           │
│  ✓ Instant UPI deposits        ✗ No live chat before 9am                      │
│  ✓ Strong cricket markets      ✗ 30x wagering is above average                │
│                                                                               │
│  Licences: MGA, UKGC     Payments: UPI · Visa · Mastercard · Paytm            │
└───────────────────────────────────────────────────────────────────────────────┘
```

Notes carried over from the plan:

- **Read Review** renders only when a `review_url_override` exists (§10.1). No mapping →
  no link, never a dead one.
- **Promo code** is suppressed when `bonus_code` is empty or the literal `"N/A"` (§A.7).
- **Logo** is `loading="eager"` for positions 1–3, `lazy` below, always with explicit
  `width`/`height` (§8.6). Falls back to a two-letter placeholder when absent.
- **Labels** ("Welcome Bonus" vs "Free Bet" vs "Rakeback") come from the product-type map.
- **Ribbon, CTA button, rank badge, brand link and star colour** all read from CSS custom
  properties (`--df-ribbon-bg`, `--df-cta-bg`, `--df-star-color`, …) set by an inline
  `<style>` block scoped to this instance, falling back to the values shown here when no
  override is set (§8.10). In WordPress these are fixed; here they're finally live.

## 8a. Grid layout — new, side-by-side cards (plan 02 §8.11)

User-requested, working from a reference screenshot. The reference turned out to be the same
row layout shown in §8 above — confirming the existing card design is already right. The
actual gap: nothing arranges multiple cards side by side. `layout: grid` fixes that, reusing
the identical card partial from §8 under a CSS-only modifier — no new template, no markup
drift between the two layouts.

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

Tablet — 2 up (~700-1050px), card 3 wraps below:
┌────────────────────────────┐  ┌────────────────────────────┐
│★ OUR TOP CHOICE            │  │                            │
│[1] ┌────┐  GambleZen       │  │[2] ┌────┐  Malina          │
│    │LOGO│  ★4.0/5          │  │    │LOGO│  ★4.5/5          │
│    └────┘  Read Review →   │  │    └────┘  Read Review →   │
│                            │  │                            │
│WELCOME BONUS               │  │WELCOME BONUS               │
│500% up to $5,450           │  │100% up to $750             │
│+ 350 Free Spins            │  │+ 200 Free Spins            │
│[ Promo: WELCOME100 ⧉ ]     │  │                            │
│                            │  │✓ 80+ providers             │
│✓ Fast payouts              │  │✓ Tiered VIP                │
│✓ 11,000+ games             │  │                            │
│                            │  │                            │
│[    Visit Site →    ]      │  │[    Visit Site →    ]      │
│More information +          │  │More information +          │
└────────────────────────────┘  └────────────────────────────┘
```

Reflows by container width, not fixed breakpoints — `repeat(auto-fit, minmax(320px, 1fr))`
inside the existing 1200px cap:

| Container width | Columns |
|---|---|
| ≥ ~1030px (desktop) | **3** |
| ~700–1029px (tablet) | **2** |
| < 700px (mobile) | **1** — visually identical to §8's row layout |

Notes:

- **Ribbon** stays on whichever card is `position === 1` — same conditional as the row
  layout, it just now renders at that card's (narrower) width instead of the full row.
- **Feature bullets** cap at 2 in grid vs 3 in cards — configurable via `maxFeatures`, not
  hardcoded; vertical space per card is tighter with 2–3 cards per row.
- **Payment icons and the metrics grid** live in "More information" only, same as today —
  they don't fit a 320px card unexpanded.
- **`columns: auto | 2 | 3`** — `auto` (the default, shown above) reflows by width; `2`/`3`
  pins the count for an editor who wants it guaranteed regardless of viewport.

---

## 9. Sync — what actually happens when you press the button

```
 Admin UI            .NET app                         DataFlair API        SQL Server
 (jQuery)            (/dataflair/admin/api)
    │                     │                                 │                   │
    │ POST sync/toplists  │                                 │                   │
    │ {page:1, perPage:25}│                                 │                   │
    ├────────────────────►│                                 │                   │
    │                     │ page 1 → clear existing rows    │                   │
    │                     ├─────────────────────────────────┼──────────────────►│
    │                     │                                 │   paginated DELETE│
    │                     │                                 │   (never TRUNCATE)│
    │                     │ GET /toplists?per_page=25&page=1│                   │
    │                     ├────────────────────────────────►│                   │
    │                     │        200 {data:[…], meta:{…}} │                   │
    │                     │◄────────────────────────────────┤                   │
    │                     │                                 │                   │
    │                     │ for each toplist:               │                   │
    │                     │   • re-wrap as {"data":{…}} ◄── the easy-to-miss one │
    │                     │   • run integrity checks        │                   │
    │                     │   • download logo if new        │                   │
    │                     │   • upsert + tracker map       ─┼──────────────────►│
    │                     │   • check deadline (25s/3s)     │                   │
    │                     │                                 │                   │
    │ {done:15, last:2}   │                                 │                   │
    │◄────────────────────┤                                 │                   │
    │                     │                                 │                   │
    │ POST sync/toplists  │   …loop until page > last_page                       │
    │ {page:2}            │                                 │                   │
    ├────────────────────►│                                 │                   │

  On 5xx:  retry ×2 (exp. backoff) → per_page 25→5→1 → per-ID fallback → report
  On deadline: return partial, admin JS resumes at the same page. No lost work.
```

The batch loop lives in the browser (or in the scheduled trigger) exactly as it does in
WordPress — it is what keeps each request inside the host's time limits.

---

## 10. Affiliate click

```
  Visitor clicks "Play Now"
          │
          ▼
  GET /go/?campaign=india-main
          │
          ▼
  ┌──────────────────────────────────────────────────────────┐
  │ GoRouteHandler                                           │
  │  1. campaign present?              no → 404              │
  │  2. look up DataFlairCache["dataflair_tracker_{md5}"]    │
  │       ↑ a real table, not MemoryCache — survives an       │
  │         app-pool recycle between page view and click     │
  │  3. validate scheme is http/https  no → 404              │
  │  4. 301 → https://track.example.com/go/casino-alpha-in   │
  └──────────────────────────────────────────────────────────┘

  Cloudflare: add a bypass-cache rule for /go/* so clicks are never
  served from edge cache and stay visible in origin logs.
```

---

## 11. What an editor actually does, end to end

```
  1. Ops        Settings → paste API token → Test Connection → ✓
  2. Ops        Dashboard → Sync Brands → Sync Toplists
  3. Ops        Brands → select all → Bulk Apply "/betting/reviews/{slug}/"
  4. Ops        Brands → fix the handful that don't follow the pattern
  5. Editor     Article → add "DataFlair Toplist" block → pick a toplist
  6. Editor     Preview → publish
  7. Ops        Tools → Review Links → weekly, fix any 404s that appear
```

Steps 1–4 are one-time setup. Step 5 is the daily loop. Step 7 is the maintenance that
manual review mapping requires and that the WordPress plugin never needed.
