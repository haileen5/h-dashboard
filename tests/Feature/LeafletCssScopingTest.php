<?php

namespace Tests\Feature;

use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Issue #1018 — completes the CSS half of #984.
 *
 * `leaflet.draw.css` and `leaflet-routing-machine.css` used to be `<link>`ed in
 * the global layout `<head>` on every page (~11.5 KB of parser-blocking CSS in
 * the hot path) although only three pages ever use the plugins they style.
 * The JS half already ships per-page via `@push('scripts')`; the CSS now does
 * the same through `@stack('styles')` in `<head>`.
 *
 * Same contract as PageScriptSplittingTest, mirrored for stylesheets: the page
 * that needs a stylesheet still gets it, every other page does not, and the
 * genuinely global stylesheets (Leaflet core, jalalidatepicker) stay in the
 * layout. Every test asserts 200 plus a page-specific marker first — a 403 or
 * 500 would satisfy each `assertDontSee` vacuously.
 */
class LeafletCssScopingTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    private const DRAW_CSS = 'css/leaflet/leaflet.draw.css';

    private const ROUTING_CSS = 'css/leaflet/leaflet-routing-machine.css';

    /** Stylesheets consumed broadly enough to stay in the layout. */
    private const GLOBAL_CSS = ['css/leaflet/leaflet.css', 'css/other/jalalidatepicker.min.css'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
        $this->withoutVite();
    }

    /**
     * A non-map page keeps Leaflet core + jalalidatepicker, but ships neither
     * page-specific plugin stylesheet anymore.
     */
    public function test_dashboard_is_not_shipped_page_specific_leaflet_css(): void
    {
        $result = $this->createUserWithUnit(['map']);
        $this->actingAs($result['user']);

        $response = $this->get('/dashboard');

        $response->assertStatus(200);
        $response->assertSee('داشبورد مدیریت اطلاعات سلامت');

        foreach (self::GLOBAL_CSS as $css) {
            $response->assertSee($css);
        }

        $response->assertDontSee(self::DRAW_CSS);
        $response->assertDontSee(self::ROUTING_CSS);
    }

    public function test_units_map_still_gets_draw_css(): void
    {
        $result = $this->createUserWithUnit(['organization']);
        $this->actingAs($result['user']);

        $response = $this->get('/units/'.$result['unit']->id.'/map');

        $response->assertStatus(200);
        $response->assertSee('id="unitMap"', false);
        $response->assertSee(self::DRAW_CSS);
        $response->assertDontSee(self::ROUTING_CSS);
    }

    public function test_maps_route_still_gets_routing_css(): void
    {
        $result = $this->createUserWithUnit(['map']);
        $this->actingAs($result['user']);

        $response = $this->get('/maps/route');

        $response->assertStatus(200);
        $response->assertSee('محاسبه فاصله جاده‌ای');
        $response->assertSee(self::ROUTING_CSS);
        $response->assertDontSee(self::DRAW_CSS);
    }

    public function test_maps_route2_still_gets_routing_css(): void
    {
        $result = $this->createUserWithUnit(['map']);
        $this->actingAs($result['user']);

        $response = $this->get('/maps/route2');

        $response->assertStatus(200);
        $response->assertSee('محاسبه فاصله جاده‌ای');
        $response->assertSee(self::ROUTING_CSS);
        $response->assertDontSee(self::DRAW_CSS);
    }

    /**
     * Cross-check: other maps pages render Leaflet maps too — they must not
     * pull the plugin stylesheets back in, or the split silently costs what
     * it saved.
     */
    public function test_plain_map_pages_do_not_get_plugin_css(): void
    {
        $result = $this->createUserWithUnit(['map']);
        $this->actingAs($result['user']);

        foreach (['/maps/county', '/maps/point'] as $url) {
            $response = $this->get($url);

            $response->assertStatus(200);
            $response->assertDontSee(self::DRAW_CSS);
            $response->assertDontSee(self::ROUTING_CSS);
        }
    }
}
