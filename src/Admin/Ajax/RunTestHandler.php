<?php
/**
 * Phase 9.6 (admin UX redesign) — Run a single diagnostic test by slug.
 *
 * Input:  { slug: string }
 * Output: { success: true, data: { slug, status, message, duration_ms, last_run_iso } }
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Ajax;

use DataFlair\Toplists\Admin\AjaxHandlerInterface;
use DataFlair\Toplists\Admin\Pages\Tools\TestsRunner;

final class RunTestHandler implements AjaxHandlerInterface
{
    public function __construct(private TestsRunner $runner) {}

    public function handle(array $request): array
    {
        $slug = isset($request['slug']) ? sanitize_key((string) $request['slug']) : '';
        if ($slug === '' || !array_key_exists($slug, TestsRunner::registry())) {
            return ['success' => false, 'data' => ['message' => 'Unknown test slug.']];
        }

        return ['success' => true, 'data' => $this->runner->run($slug)];
    }
}
