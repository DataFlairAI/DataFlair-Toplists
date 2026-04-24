<?php
/**
 * Sync result value object. Return shape for every sync service entry point.
 *
 * Mirrors the exact response shape the AJAX handlers have always emitted via
 * wp_send_json_success / wp_send_json_error so the admin JS receives byte-
 * identical payloads.
 *
 * @package DataFlair\Toplists\Sync
 * @since   1.12.1 (Phase 3)
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Sync;

final class SyncResult
{
    /** @var array<string,mixed> */
    private array $extra;

    public function __construct(
        public readonly bool $success,
        public readonly int $page,
        public readonly int $lastPage,
        public readonly int $synced,
        public readonly int $errors,
        public readonly bool $partial,
        public readonly int $nextPage,
        public readonly bool $isComplete,
        public readonly string $message = '',
        array $extra = []
    ) {
        $this->extra = $extra;
    }

    public static function success(
        int $page,
        int $lastPage,
        int $synced,
        int $errors,
        bool $partial,
        bool $isComplete,
        array $extra = []
    ): self {
        return new self(
            true,
            $page,
            $lastPage,
            $synced,
            $errors,
            $partial,
            $partial ? $page : ($page + 1),
            $isComplete,
            '',
            $extra
        );
    }

    public static function failure(int $page, string $message): self
    {
        return new self(false, $page, 0, 0, 0, false, $page, false, $message);
    }

    /**
     * Return the payload shape the AJAX handler wraps in wp_send_json_*.
     * Extra keys (fallback, skipped, skip_reason, total_synced, total_brands)
     * override any default keys so callers can pass the exact legacy shape.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        if (!$this->success) {
            return ['message' => $this->message];
        }

        $base = [
            'page'        => $this->page,
            'last_page'   => $this->lastPage,
            'synced'      => $this->synced,
            'errors'      => $this->errors,
            'partial'     => $this->partial,
            'next_page'   => $this->nextPage,
            'is_complete' => $this->isComplete,
        ];
        return array_merge($base, $this->extra);
    }
}
