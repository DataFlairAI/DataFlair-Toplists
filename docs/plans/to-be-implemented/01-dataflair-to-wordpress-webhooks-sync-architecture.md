# DataFlair ↔ WP Plugin Sync Architecture

> **Status:** parked, not started
> **Created:** 2026-04-25
> **Owner:** Mex
> **Touches:** `dataflair.ai-v2` (Laravel API) + `DataFlair-Toplists` (WP plugin)
> **Branches when picked up:** one PR per side, sequenced A → B → C

## Goal

Replace the current pull-based "Fetch All Toplists" sync (60 s, full TRUNCATE+INSERT every time) with a delta-aware system that scales as toplist count grows. Webhooks are the long-term destination but only after delta-sync correctness lands first.

## Architectural truth

**Delta-sync is correctness. Webhooks are latency.** Don't conflate them. Build correctness first, then optimize for speed.

---

## What dataflair already has (audit done 2026-04-25 on `staging` branch)

- Multi-tenant Laravel via Stancl/Tenancy by subdomain ([routes/api-v2.php](https://github.com/strikepass/dataflair.ai-v2/blob/staging/routes/api-v2.php))
- `SiteApiCredential` model — natural attach point for webhook subscriptions (per-site, per-credential)
- `TopList::updated_at` already indexed and the v2 API already orders by it (`TopListApiV2Controller::index` line 104)
- `LogsActivity` trait on `TopList` — every change is auditable; observers exist but don't fan out anywhere
- Inbound webhook pattern in place (`PloiWebhookController` with HMAC verification) — mirror this for outbound
- Comments already say "future: webhooks" in `DealController`, `OfferController` — intent exists, unwired
- Laravel queues — retry / backoff / dead-letter come for free with `ShouldQueue`

## What's missing

- No `?modified_since=` filter on `/api/v2/toplists` or `/api/v2/brands`
- No outbound webhook infrastructure (no `WebhookSubscription` model, no dispatcher job)
- No tombstones — `TopList` and `Brand` have no `SoftDeletes`. Hard delete = plugin can never know it's gone except via full TRUNCATE re-sync
- No model observers fanning out events for `TopList`, `Brand`, `Offer`

---

## Phase A — Delta endpoint (1–2 days)

**Solves ~95 % of the current pain by itself. Ships independently. Lowest risk.**

### Server side (`dataflair.ai-v2`)

`app/Http/Controllers/Tenant/API/V2/TopListApiV2Controller.php` — add to `index()`:

```php
$request->validate([
    // ... existing rules
    'modified_since' => 'sometimes|date',
]);
if ($request->filled('modified_since')) {
    $query->where('updated_at', '>', $request->date('modified_since'));
}
```

Same on `BrandApiV2Controller`. That's the entire server change. No new endpoint, no new auth, no new docs page — just one query param.

### Plugin side (`DataFlair-Toplists`)

- New WP-CLI command: `wp dataflair sync:delta`
  - Reads `dataflair_last_toplists_sync` option
  - Fetches `?modified_since=$last_synced_at`
  - Upserts only changed toplists via existing `ToplistsRepository`
  - Updates `dataflair_last_toplists_sync` to "now" only on success
- Replace page=1 wipe with upsert path everywhere except the explicit "rebuild from scratch" button
- Keep "Fetch All Toplists from API" as the nuclear-option rebuild button

### Acceptance

- Day 2 sync of 5 changed toplists: < 2 s
- Full re-sync still works via the legacy button
- `composer perf` tier-Sigma sync scenario unchanged

---

## Phase B — Tombstones for deletions (1 day)

After Phase A, the only reason the plugin still does the page-1 wipe is to catch deletions. Add soft deletes to learn about them.

### Server side

```bash
php artisan make:migration add_soft_deletes_to_toplists_and_brands
```

```php
Schema::table('toplists', fn ($t) => $t->softDeletes());
Schema::table('brands', fn ($t) => $t->softDeletes());
```

`TopList` and `Brand` models: add `use SoftDeletes;`.

API controllers:

```php
if ($request->boolean('include_trashed')) {
    $query->withTrashed()->where('updated_at', '>', $since);
}
```

Tombstones come back with `deleted_at` set.

### Plugin side

- Delta sync now requests `?modified_since=…&include_trashed=1`
- For each row with `deleted_at`: `ToplistsRepository::deleteById($id)` (or `BrandsRepository::deleteById($id)`)
- Page-1 wipe is removed entirely

### Acceptance

- Toplist soft-deleted on dataflair → next delta sync removes it from WP
- No more TRUNCATE on incremental sync runs

---

## Phase C — Webhooks (~1 week)

Only worth doing **after A + B**. With delta-sync already correct, webhooks become a latency optimization rather than a correctness mechanism — much lower stakes.

### Server side

**New table:**

```php
Schema::create('site_webhook_subscriptions', function (Blueprint $t) {
    $t->id();
    $t->foreignId('site_api_credential_id')->constrained()->cascadeOnDelete();
    $t->string('url', 500);
    $t->string('signing_secret', 64);          // HMAC-SHA256
    $t->json('event_types');                    // ['toplist.*', 'brand.updated', ...]
    $t->boolean('is_active')->default(true);
    $t->timestamp('last_delivered_at')->nullable();
    $t->unsignedInteger('failure_count')->default(0);
    $t->timestamps();
});
```

**Model observer (one per resource):**

```php
class TopListWebhookObserver
{
    public function updated(TopList $toplist): void
    {
        DispatchWebhookJob::dispatch('toplist.updated', $toplist);
    }
    public function deleted(TopList $toplist): void
    {
        DispatchWebhookJob::dispatch('toplist.deleted', $toplist);
    }
}
```

**Queued job (mirrors `PloiWebhookController` HMAC pattern):**

```php
class DispatchWebhookJob implements ShouldQueue
{
    public int $tries = 5;
    public array $backoff = [60, 300, 1800, 7200, 43200];  // 1m, 5m, 30m, 2h, 12h

    public function handle(): void
    {
        foreach ($this->subscribersFor($this->event) as $sub) {
            $body = json_encode([
                'event'       => $this->event,
                'event_id'    => (string) Str::ulid(),
                'occurred_at' => now()->toIso8601String(),
                'data'        => $this->payload,
            ]);
            $sig = hash_hmac('sha256', $body, $sub->signing_secret);

            Http::timeout(10)
                ->withHeaders([
                    'X-Dataflair-Signature' => $sig,
                    'X-Dataflair-Event'     => $this->event,
                ])
                ->post($sub->url, $body);
        }
    }
}
```

### Plugin side

- New `src/Rest/Controllers/WebhookController.php`
  - Verifies HMAC signature against `dataflair_webhook_secret` option
  - Reject if `occurred_at` drifts > 5 min from server clock
- New table `wp_dataflair_processed_events (event_id PK, processed_at)` for idempotency
- Event router → existing repos:

| Event | Plugin handler |
|---|---|
| `toplist.updated` | `ToplistsRepository::upsert($payload)` |
| `toplist.deleted` | `ToplistsRepository::deleteById($id)` |
| `brand.updated` | `BrandsRepository::upsert($payload)` |
| `brand.logo_updated` | `LogoDownloader::download($brand_id)` |
| `offer.updated` | re-sync parent toplist (offer change rebuilds card) |
| `alternative_toplist.updated` | `AlternativesRepository::upsert($payload)` |

- Returns `202 Accepted` in < 100 ms. Heavy work (logo download) goes to WP-Cron / Action Scheduler.

### Acceptance

- Toplist edited in dataflair admin → WP reflects in < 5 s
- Network blip on plugin side → next hourly delta sync (Phase A) catches up
- Replayed event with same `event_id` is a no-op

---

## Phase D (optional) — Subscription management UI

In dataflair's tenant dashboard:

- List webhook URLs per site
- Rotate signing secret
- View recent deliveries (success / failure / payload)
- Replay failed events button

Mirrors Stripe's webhook dashboard. Skip if dataflair team is constrained — settings via Tinker / DB writes is fine for v1.

---

## Risks / things that bite regardless of approach

- **Idempotency:** events arriving out of order, duplicates from retries → idempotency table is mandatory
- **Clock skew:** HMAC timestamp drift, `modified_since` boundary races → use server clock, not client
- **Hard deletes without tombstones:** easy to miss → Phase B is non-optional if going past Phase A
- **Schema evolution:** dataflair adds a field — plugin shouldn't break, just store + ignore (already handled via JSON blob storage in `wp_dataflair_toplists.data`)

## Cross-cutting rules

- Coordinate the Phase A PR with the dataflair-v2 deploy — plugin can't request `?modified_since=` until the server understands it. Land server side first, deploy, then ship plugin side.
- HMAC signing secret is rotatable. Plugin settings UI must allow paste-in of new secret without losing in-flight events.
- `event_id` is a ULID, not a UUID — sortable for replay debugging.

## Recommended order

1. **Phase A this week** — delta endpoint. One PR per side. Biggest win, lowest risk.
2. **Phase B next week** — soft deletes + tombstones. Removes plugin's page-1 wipe.
3. **Phase C when there's appetite** — webhooks for sub-second latency. By then the system is already correct via A + B; only optimizing latency.
4. **Phase D later, if useful** — webhook subscription UI in dataflair tenant dashboard.

## Open questions

1. Does dataflair's `Offer` model need its own delta endpoint, or is it always reached via parent `TopList`?
2. Should `brand.logo_updated` be a separate event, or rolled into `brand.updated`? (Logo downloads are heavy; separate event lets WP queue them differently.)
3. Webhook delivery timeout: 10 s reasonable, or tighter (5 s) given queue retries handle slow receivers anyway?
4. Plugin "rebuild from scratch" button: keep, or remove once Phase B lands? (Lean: keep — it's the recovery escape hatch.)
