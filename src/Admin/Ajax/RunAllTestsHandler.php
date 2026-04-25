<?php
/**
 * Phase 9.6 (admin UX redesign) — Run the full diagnostic test suite.
 *
 * Output: { success: true, data: { results: { slug: row, … }, summary: { pass, fail, warn } } }
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Ajax;

use DataFlair\Toplists\Admin\AjaxHandlerInterface;
use DataFlair\Toplists\Admin\Pages\Tools\TestsRunner;

final class RunAllTestsHandler implements AjaxHandlerInterface
{
    public function __construct(private TestsRunner $runner) {}

    public function handle(array $request): array
    {
        $results = $this->runner->runAll();

        $pass = $fail = $warn = 0;
        foreach ($results as $r) {
            match ($r['status']) {
                'pass'  => $pass++,
                'fail'  => $fail++,
                default => $warn++,
            };
        }

        return [
            'success' => true,
            'data'    => [
                'results' => $results,
                'summary' => ['pass' => $pass, 'fail' => $fail, 'warn' => $warn],
            ],
        ];
    }
}
