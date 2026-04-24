<?php
/**
 * WallClockBudget — cooperative time budget for long-running work.
 *
 * Sync handlers on shared hosts hit hard wall-clock limits (nginx/Apache
 * FastCGI timeouts are typically 30 s). Rather than relying on
 * `set_time_limit()` — which is unreliable on hosts that disable it, and
 * which kills the process without letting the caller return a useful
 * `partial:true` response — we pass a budget through the call chain and
 * each unit of work checks `remaining()` before starting the next chunk.
 *
 * Introduced in v1.11.0 as part of Phase 0B H13. Used by `api_get()`,
 * `download_brand_logo()`, AJAX sync batch handlers, and the chunked
 * transient sweep (`clear_tracker_transients()`).
 *
 * Contract:
 *   $budget = new WallClockBudget(25.0);   // 25 s total
 *   while ($work_left && !$budget->exceeded(3.0)) {   // keep 3 s headroom
 *       do_one_item();
 *   }
 *   if ($budget->exceeded(3.0)) {
 *       return ['partial' => true, 'next_page' => …];
 *   }
 *
 * `exceeded()` accepts an optional headroom so callers can bail before the
 * hard limit, leaving time for response serialization + DB flush.
 */

namespace DataFlair\Toplists\Support;

final class WallClockBudget {
    private float $started_at;
    private float $budget_seconds;

    public function __construct(float $budget_seconds) {
        $this->started_at     = microtime(true);
        $this->budget_seconds = max(0.0, $budget_seconds);
    }

    /** Wall-clock seconds spent so far. */
    public function elapsed(): float {
        return microtime(true) - $this->started_at;
    }

    /** Wall-clock seconds left before the budget is gone. Clamped at 0. */
    public function remaining(): float {
        $left = $this->budget_seconds - $this->elapsed();
        return $left > 0.0 ? $left : 0.0;
    }

    /**
     * True when remaining() <= $headroom.
     *
     * Callers pass a headroom that matches the cost of the next work unit
     * plus response-serialization overhead. Passing 0.0 (default) means
     * "has the full budget run out yet?".
     */
    public function exceeded(float $headroom = 0.0): bool {
        return $this->remaining() <= max(0.0, $headroom);
    }

    public function total(): float {
        return $this->budget_seconds;
    }
}
