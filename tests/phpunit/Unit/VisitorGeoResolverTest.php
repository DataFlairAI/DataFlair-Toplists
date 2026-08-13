<?php
/**
 * Phase 3 (geo-targeting) — pins Geo\VisitorGeoResolver behaviour.
 *
 * Covers every branch of the header/filter cascade:
 *   - CF-IPCountry header wins when present, normalized to uppercase+trim
 *   - CF-IPCountry takes priority over X-Geoip-Country when both are set
 *   - falls back to X-Geoip-Country when CF-IPCountry is absent
 *   - CF's XX/T1 sentinel values are treated as unresolved, not literal codes
 *   - falls back to the `dataflair_visitor_country` filter when both headers
 *     are absent or sentinel
 *   - a non-string filter return is treated as unresolved
 *   - returns null when nothing resolves (callers must default-deny)
 *   - an empty-string header is treated as absent, not a literal code
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Geo;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use DataFlair\Toplists\Geo\VisitorGeoResolver;
use PHPUnit\Framework\TestCase;

final class VisitorGeoResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $_SERVER = [];
        $_GET    = [];
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_resolves_from_cf_ipcountry_header_and_normalizes_case(): void
    {
        $_SERVER['HTTP_CF_IPCOUNTRY'] = ' gb ';

        $this->assertSame('GB', (new VisitorGeoResolver())->resolve());
    }

    public function test_cf_ipcountry_takes_priority_over_x_geoip_country(): void
    {
        $_SERVER['HTTP_CF_IPCOUNTRY']   = 'DE';
        $_SERVER['HTTP_X_GEOIP_COUNTRY'] = 'FR';

        $this->assertSame('DE', (new VisitorGeoResolver())->resolve());
    }

    public function test_falls_back_to_x_geoip_country_when_cf_absent(): void
    {
        $_SERVER['HTTP_X_GEOIP_COUNTRY'] = 'it';

        $this->assertSame('IT', (new VisitorGeoResolver())->resolve());
    }

    public function test_cf_sentinel_xx_falls_through_to_x_geoip_country(): void
    {
        $_SERVER['HTTP_CF_IPCOUNTRY']   = 'XX';
        $_SERVER['HTTP_X_GEOIP_COUNTRY'] = 'ES';

        $this->assertSame('ES', (new VisitorGeoResolver())->resolve());
    }

    public function test_cf_sentinel_t1_falls_through_to_filter(): void
    {
        $_SERVER['HTTP_CF_IPCOUNTRY'] = 'T1';

        Filters\expectApplied('dataflair_visitor_country')
            ->once()
            ->andReturn('NL');

        $this->assertSame('NL', (new VisitorGeoResolver())->resolve());
    }

    public function test_falls_back_to_filter_when_no_headers_present(): void
    {
        Filters\expectApplied('dataflair_visitor_country')
            ->once()
            ->andReturn('IN');

        $this->assertSame('IN', (new VisitorGeoResolver())->resolve());
    }

    public function test_returns_null_when_filter_returns_null(): void
    {
        Filters\expectApplied('dataflair_visitor_country')
            ->once()
            ->andReturn(null);

        $this->assertNull((new VisitorGeoResolver())->resolve());
    }

    public function test_non_string_filter_return_is_treated_as_unresolved(): void
    {
        Filters\expectApplied('dataflair_visitor_country')
            ->once()
            ->andReturn(['unexpected' => 'array']);

        $this->assertNull((new VisitorGeoResolver())->resolve());
    }

    public function test_empty_string_header_is_treated_as_absent(): void
    {
        $_SERVER['HTTP_CF_IPCOUNTRY']   = '';
        $_SERVER['HTTP_X_GEOIP_COUNTRY'] = 'PT';

        $this->assertSame('PT', (new VisitorGeoResolver())->resolve());
    }

    public function test_query_param_override_wins_for_admin(): void
    {
        $_GET['dataflair_geo'] = 'gb';
        Functions\when('current_user_can')->justReturn(true);

        $this->assertSame('GB', (new VisitorGeoResolver())->resolve());
    }

    public function test_query_param_override_beats_headers_for_admin(): void
    {
        $_GET['dataflair_geo']         = 'FR';
        $_SERVER['HTTP_CF_IPCOUNTRY']  = 'DE';
        Functions\when('current_user_can')->justReturn(true);

        $this->assertSame('FR', (new VisitorGeoResolver())->resolve());
    }

    public function test_query_param_override_ignored_for_non_admin(): void
    {
        $_GET['dataflair_geo']        = 'GB';
        $_SERVER['HTTP_CF_IPCOUNTRY'] = 'DE';
        Functions\when('current_user_can')->justReturn(false);

        $this->assertSame('DE', (new VisitorGeoResolver())->resolve());
    }

    public function test_query_param_override_ignored_when_param_absent(): void
    {
        $_SERVER['HTTP_CF_IPCOUNTRY'] = 'DE';
        Functions\when('current_user_can')->justReturn(true);

        $this->assertSame('DE', (new VisitorGeoResolver())->resolve());
    }
}
