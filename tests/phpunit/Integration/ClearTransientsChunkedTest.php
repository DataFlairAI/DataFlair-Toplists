<?php
/**
 * H10 / H11 regression test: bulk delete paths must be chunked.
 *
 * Sigma latent-OOM / replication-safety concern:
 * - clear_tracker_transients() previously issued a single unbounded DELETE
 *   against wp_options. On a site with 100k+ accumulated tracker transients
 *   this hits the MySQL max_allowed_packet ceiling, blows binlog row-size,
 *   and can deadlock under row-based replication.
 * - A plain TRUNCATE on wp_dataflair_* tables is not safely replicable on
 *   all managed MySQL hosts (implicit commit, metadata lock, STATEMENT-based
 *   binlog skew).
 *
 * This is a structural scan of the plugin file. Execution-path coverage
 * lands in Phase 2 when the delete helpers migrate into a testable
 * repository class.
 */

use PHPUnit\Framework\TestCase;

class ClearTransientsChunkedTest extends TestCase {

    private string $source = '';

    protected function setUp(): void {
        parent::setUp();
        $path = DATAFLAIR_PLUGIN_DIR . 'dataflair-toplists.php';
        $this->source = (string) file_get_contents($path);
        $this->assertNotSame('', $this->source, "Plugin file must be readable at {$path}.");
    }

    // ── H10: clear_tracker_transients is chunked ──────────────────────────

    public function test_clear_tracker_transients_uses_limit_per_statement(): void {
        $body = $this->extractMethodBody('clear_tracker_transients');
        $this->assertNotEmpty($body, 'clear_tracker_transients() body must be present in plugin file.');

        $this->assertMatchesRegularExpression(
            '/LIMIT\s+%d/i',
            $body,
            'clear_tracker_transients() must DELETE with a LIMIT clause — chunked deletion per H10.'
        );
    }

    public function test_clear_tracker_transients_accepts_optional_budget(): void {
        $signature = $this->extractMethodSignature('clear_tracker_transients');
        $this->assertMatchesRegularExpression(
            '/\$budget\s*=\s*null/',
            $signature,
            'clear_tracker_transients() must accept optional WallClockBudget. Signature: ' . $signature
        );
    }

    public function test_clear_tracker_transients_bails_when_budget_exhausted(): void {
        $body = $this->extractMethodBody('clear_tracker_transients');
        $this->assertMatchesRegularExpression(
            '/\$budget[^;]*->exceeded\(/',
            $body,
            'clear_tracker_transients() must check $budget->exceeded() inside its loop to yield to the caller.'
        );
    }

    // ── H11: delete_all_paginated replaces TRUNCATE ──────────────────────

    public function test_delete_all_paginated_helper_is_defined(): void {
        $this->assertMatchesRegularExpression(
            '/function\s+delete_all_paginated\s*\(/',
            $this->source,
            'delete_all_paginated() helper must exist — H11 TRUNCATE replacement.'
        );
    }

    public function test_delete_all_paginated_uses_limit_chunk(): void {
        $body = $this->extractMethodBody('delete_all_paginated');
        $this->assertMatchesRegularExpression(
            '/DELETE\s+FROM\s+\$?\w+\s+LIMIT\s+%d/i',
            $body,
            'delete_all_paginated() must DELETE FROM <table> LIMIT %d in a loop (chunked).'
        );
    }

    public function test_delete_all_paginated_whitelists_table_argument(): void {
        $body = $this->extractMethodBody('delete_all_paginated');
        $this->assertStringContainsString(
            'in_array($table',
            $body,
            'delete_all_paginated() must whitelist its $table argument against the plugin\'s known tables to prevent SQL injection.'
        );
    }

    public function test_plugin_no_longer_uses_truncate_on_dataflair_tables(): void {
        $this->assertDoesNotMatchRegularExpression(
            '/TRUNCATE\s+TABLE/i',
            $this->source,
            'TRUNCATE TABLE must not appear anywhere in the plugin — H11 replaced it with delete_all_paginated().'
        );
    }

    // ── H9: schema version transient gate ─────────────────────────────────

    public function test_check_database_upgrade_uses_schema_ok_transient(): void {
        $body = $this->extractMethodBody('check_database_upgrade');
        $this->assertStringContainsString(
            'dataflair_schema_ok_v',
            $body,
            'check_database_upgrade() must consult the dataflair_schema_ok_v{version} transient (H9).'
        );
        $this->assertMatchesRegularExpression(
            '/get_transient\s*\(\s*\$schema_ok_key\s*\)/',
            $body,
            'check_database_upgrade() must short-circuit when the schema-ok transient is set.'
        );
        $this->assertMatchesRegularExpression(
            '/set_transient\s*\(\s*\$schema_ok_key\s*,/',
            $body,
            'check_database_upgrade() must set the schema-ok transient after a successful pass.'
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function extractMethodBody(string $methodName): string {
        $signaturePattern = '/function\s+' . preg_quote($methodName, '/') . '\s*\(/';
        if (!preg_match($signaturePattern, $this->source, $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        $start = $m[0][1];
        $openBrace = strpos($this->source, '{', $start);
        if ($openBrace === false) return '';

        $depth = 1;
        $i     = $openBrace + 1;
        $len   = strlen($this->source);
        while ($i < $len && $depth > 0) {
            $ch = $this->source[$i];
            if ($ch === '{') $depth++;
            elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($this->source, $openBrace + 1, $i - $openBrace - 1);
                }
            }
            $i++;
        }
        return '';
    }

    private function extractMethodSignature(string $methodName): string {
        $pattern = '/function\s+' . preg_quote($methodName, '/') . '\s*\(/';
        if (!preg_match($pattern, $this->source, $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }
        $start = $m[0][1];
        $openParen = strpos($this->source, '(', $start);
        if ($openParen === false) return '';

        $depth = 1;
        $i     = $openParen + 1;
        $len   = strlen($this->source);
        while ($i < $len && $depth > 0) {
            $ch = $this->source[$i];
            if ($ch === '(') $depth++;
            elseif ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    return substr($this->source, $start, ($i + 1) - $start);
                }
            }
            $i++;
        }
        return '';
    }
}
