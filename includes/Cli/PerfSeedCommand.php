<?php
/**
 * WP-CLI command: `wp dataflair perf:seed --tier={S|Sigma|L|XL|P}`
 *
 * Phase 0.5 perf-rig seeder. Generates deterministic synthetic fixtures
 * into wp_dataflair_toplists + wp_dataflair_brands so that perf scenarios
 * (render / sync / admin / rest) exercise realistic row counts and JSON
 * payload sizes without hitting the upstream DataFlair API.
 *
 * Tiers (row counts + avg JSON size per row):
 *   S      10 toplists  ×  5 items,    50 brands,   ~50 KB/toplist   (smoke)
 *   Sigma  200 toplists × 20 items,   500 brands,  ~200 KB/toplist   (prod-like)
 *   L      500 toplists × 20 items, 1,000 brands,  ~200 KB/toplist
 *   XL    1000 toplists × 20 items, 2,000 brands,  ~200 KB/toplist
 *   P     2000 toplists × 30 items, 5,000 brands,  ~500 KB/toplist   (punishing)
 *
 * The seed is idempotent: running `perf:seed --tier=Sigma` twice produces
 * the same rows. Rows are truncated (via delete_all_paginated if present,
 * else a chunked DELETE) before re-seeding to guarantee determinism.
 *
 * Flags:
 *   --tier=<tier>   Required. One of S, Sigma, L, XL, P (case-insensitive).
 *   --clean         Delete all plugin rows first (default: true).
 *   --quiet         Suppress per-item progress (useful in CI).
 *
 * This command exists only for the perf rig. It never ships with
 * production data. It deliberately skips the upstream API path — the
 * point is to exercise the plugin's PHP layer under a known load.
 */
declare(strict_types=1);

namespace DataFlair\Toplists\Cli;

class PerfSeedCommand
{
    private const TIERS = [
        'S'     => ['toplists' => 10,   'items' => 5,  'brands' => 50,   'payload_kb' => 50],
        'SIGMA' => ['toplists' => 200,  'items' => 20, 'brands' => 500,  'payload_kb' => 200],
        'L'     => ['toplists' => 500,  'items' => 20, 'brands' => 1000, 'payload_kb' => 200],
        'XL'    => ['toplists' => 1000, 'items' => 20, 'brands' => 2000, 'payload_kb' => 200],
        'P'     => ['toplists' => 2000, 'items' => 30, 'brands' => 5000, 'payload_kb' => 500],
    ];

    /**
     * @param array<int, string>            $args
     * @param array<string, string|bool>    $assoc_args
     */
    public function __invoke(array $args, array $assoc_args): void
    {
        global $wpdb;

        $tier = strtoupper((string) ($assoc_args['tier'] ?? ''));
        if (!isset(self::TIERS[$tier])) {
            \WP_CLI::error('--tier is required. One of: ' . implode(', ', array_keys(self::TIERS)));
        }
        $spec  = self::TIERS[$tier];
        $clean = !isset($assoc_args['clean']) || $assoc_args['clean'] !== 'false';
        $quiet = isset($assoc_args['quiet']);

        $toplists_table = $wpdb->prefix . DATAFLAIR_TABLE_NAME;
        $brands_table   = $wpdb->prefix . DATAFLAIR_BRANDS_TABLE_NAME;

        if ($clean) {
            if (!$quiet) { \WP_CLI::log("Cleaning existing plugin rows…"); }
            $this->truncate_chunked($toplists_table);
            $this->truncate_chunked($brands_table);
        }

        if (!$quiet) {
            \WP_CLI::log(sprintf(
                "Seeding tier=%s: %d toplists × %d items, %d brands, ~%d KB/payload",
                $tier, $spec['toplists'], $spec['items'], $spec['brands'], $spec['payload_kb']
            ));
        }

        $this->seed_brands($brands_table, $spec['brands'], $quiet);
        $this->seed_toplists($toplists_table, $spec['toplists'], $spec['items'], $spec['brands'], $spec['payload_kb'], $quiet);

        \WP_CLI::success(sprintf(
            "Seeded tier=%s (%d toplists, %d brands).",
            $tier, $spec['toplists'], $spec['brands']
        ));
    }

    private function truncate_chunked(string $table): void
    {
        global $wpdb;
        // Mirror H11: chunked DELETE for replication safety.
        do {
            $n = (int) $wpdb->query("DELETE FROM `{$table}` LIMIT 1000");
        } while ($n > 0);
    }

    private function seed_brands(string $table, int $count, bool $quiet): void
    {
        global $wpdb;
        for ($i = 1; $i <= $count; $i++) {
            $api_brand_id = 10000 + $i;
            $name         = sprintf('PerfBrand %04d', $i);
            $slug         = sprintf('perfbrand-%04d', $i);

            $data = [
                'id'          => $api_brand_id,
                'name'        => $name,
                'slug'        => $slug,
                'logo'        => [
                    'rectangular' => "https://cdn.example/perf/{$slug}-rect.png",
                    'square'      => "https://cdn.example/perf/{$slug}-sq.png",
                ],
                'rating'      => 3.5 + (($i % 15) / 10.0),
                'payment_methods' => ['visa', 'mastercard', 'skrill', 'neteller'],
                'licenses'    => ['MGA', 'UKGC'],
                'top_geos'    => ['US', 'CA', 'UK', 'DE', 'FR'],
            ];

            $ok = $wpdb->insert($table, [
                'api_brand_id'    => $api_brand_id,
                'name'            => $name,
                'slug'            => $slug,
                'status'          => 'Active',
                'data'            => wp_json_encode($data),
                'licenses'        => 'MGA,UKGC',
                'top_geos'        => 'US,CA,UK,DE,FR',
                'product_types'   => 'casino,sportsbook',
                'local_logo_url'  => "https://cdn.example/perf/{$slug}-rect.png",
                'last_synced'     => current_time('mysql'),
            ]);
            if ($ok === false) {
                \WP_CLI::error("brand insert failed: " . $wpdb->last_error);
            }

            if (!$quiet && $i % 100 === 0) {
                \WP_CLI::log("  … {$i} brands seeded");
            }
        }
    }

    private function seed_toplists(
        string $table,
        int $count,
        int $items_per,
        int $brand_pool,
        int $payload_kb,
        bool $quiet
    ): void {
        global $wpdb;

        // Build a reusable filler string sized to hit approximately the
        // requested payload KB. Gets baked into each item to simulate the
        // verbose upstream payload shape.
        $filler = str_repeat('x', (int) max(0, $payload_kb * 1024 / max($items_per, 1) / 2));

        for ($t = 1; $t <= $count; $t++) {
            $api_toplist_id = 20000 + $t;
            $name           = sprintf('PerfToplist %04d', $t);
            $slug           = sprintf('perftoplist-%04d', $t);

            $items = [];
            for ($i = 0; $i < $items_per; $i++) {
                $brand_i  = ((($t - 1) * $items_per) + $i) % $brand_pool + 1;
                $brand_id = 10000 + $brand_i;
                $items[]  = [
                    'id'       => $api_toplist_id * 100 + $i,
                    'position' => $i + 1,
                    'rating'   => 4.0 + (($i % 10) / 10.0),
                    'brand'    => [
                        'id'   => $brand_id,
                        'name' => sprintf('PerfBrand %04d', $brand_i),
                        'slug' => sprintf('perfbrand-%04d', $brand_i),
                    ],
                    'offer'    => [
                        'offerText' => "100% up to \$500 + 50 Free Spins (perf item {$i})",
                        'bonus_code' => ($i % 3 === 0) ? 'PERFBONUS' : '',
                    ],
                    'pros'     => ['Great game selection', 'Fast payouts', 'Crypto supported'],
                    'cons'     => ['High wagering requirements', 'Limited customer support hours'],
                    // Filler to hit target payload size.
                    'description' => $filler,
                ];
            }

            $payload = [
                'data' => [
                    'id'        => $api_toplist_id,
                    'name'      => $name,
                    'slug'      => $slug,
                    'items'     => $items,
                    'template'  => ['name' => 'perf-template'],
                ],
            ];

            $ok = $wpdb->insert($table, [
                'api_toplist_id' => $api_toplist_id,
                'name'           => $name,
                'slug'           => $slug,
                'item_count'     => $items_per,
                'data'           => wp_json_encode($payload),
                'last_synced'    => current_time('mysql'),
            ]);
            if ($ok === false) {
                \WP_CLI::error("toplist insert failed: " . $wpdb->last_error);
            }

            if (!$quiet && $t % 50 === 0) {
                \WP_CLI::log("  … {$t} toplists seeded");
            }
        }
    }
}
