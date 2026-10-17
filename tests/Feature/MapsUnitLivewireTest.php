<?php

namespace Tests\Feature;

use App\Models\Region;
use App\Models\Unit;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

class MapsUnitLivewireTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    protected function seedRegionAndTypes(): Region
    {
        $region = Region::create(['name' => 'شهرستان تست', 'type' => 'county']);

        // UnitType::$fillable excludes 'id'; use DB::table for explicit IDs
        DB::table('unit_types')->insert([
            ['id' => 5, 'name' => 'مرکز جامع سلامت', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 6, 'name' => 'مرکز خدمات جامع سلامت', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 7, 'name' => 'پایگاه سلامت', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::select("SELECT setval('unit_types_id_seq', GREATEST((SELECT COALESCE(MAX(id),0) FROM unit_types), 1))");

        return $region;
    }

    protected function createBoundary(): int
    {
        DB::statement(
            'INSERT INTO boundaries (boundary, created_at, updated_at) VALUES '
            ."(ST_GeomFromText('MULTIPOLYGON(((0 0, 1 0, 1 1, 0 1, 0 0)))', 4326), now(), now())"
        );

        return (int) DB::getPdo()->lastInsertId();
    }

    // ==================== Auth / permissions ====================

    public function test_guest_302(): void
    {
        $this->get('/maps/unit')->assertRedirect('/login');
    }

    public function test_unauthorized_403(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_users']);
        $this->actingAs($user);

        $this->get('/maps/unit')->assertStatus(403);
    }

    public function test_renders(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        Livewire::test('maps/unit')
            ->assertStatus(200)
            ->assertSee('نقشه واحد ها');
    }

    // ==================== Region scope (#1032) ====================

    /**
     * #1032: bumping maps_version busts every map cache key — the component's
     * own invalidation channel — so Redis-persisted entries from other tests
     * (same row ids under RefreshDatabase) can't bleed into these assertions.
     */
    protected function bustMapCaches(): void
    {
        Cache::increment('maps_version');
    }

    /**
     * #1032: region geometry must follow the accessible-unit scope, exactly
     * like `maps/county` — a client-settable `selectedRegions` value may name
     * a region outside the user's organizational scope.
     */
    public function test_region_geometry_outside_scope_is_not_served(): void
    {
        $this->bustMapCaches();
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        $this->seedRegionAndTypes();
        $outside = Region::create(['name' => 'شهرستان بیرونی', 'type' => 'county']);
        $outside->update(['boundary_id' => $this->createBoundary()]);

        Livewire::test('maps/unit')
            ->set('selectedRegions', [$outside->id])
            ->assertDispatched('county-boundaries-loaded', function (string $event, array $data) use (&$served): bool {
                $served = $data['counties'] ?? null;

                return true;
            });

        $this->assertSame([], $served, 'Region boundaries outside the accessible scope were served.');
    }

    public function test_region_list_is_scoped_to_accessible_regions(): void
    {
        $this->bustMapCaches();
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        $inScope = $this->seedRegionAndTypes();
        $unit->update(['region_id' => $inScope->id]);

        Region::create(['name' => 'شهرستان بیرونی', 'type' => 'county']);

        Livewire::test('maps/unit')
            ->assertSee('شهرستان تست')
            ->assertDontSee('شهرستان بیرونی');
    }

    /**
     * #1032: the cache key must carry the scope. If a user with a wide scope
     * warms the cache for a region, a narrow-scope user selecting the SAME
     * `selectedRegions` value must not receive the warmed geometry.
     */
    public function test_cache_does_not_leak_between_users_with_different_scope(): void
    {
        $this->bustMapCaches();
        ['user' => $wideUser, 'unit' => $wideUnit] = $this->createUserWithUnit(['map']);
        $this->actingAs($wideUser);

        $target = $this->seedRegionAndTypes();
        // The wide-scope unit lives in the target region.
        $wideUnit->update(['region_id' => $target->id]);
        $target->update(['boundary_id' => $this->createBoundary()]);

        $servedToWide = null;
        Livewire::test('maps/unit')
            ->set('selectedRegions', [$target->id])
            ->assertDispatched('county-boundaries-loaded', function (string $event, array $data) use (&$servedToWide): bool {
                $servedToWide = $data['counties'] ?? null;

                return true;
            });
        $this->assertNotEmpty($servedToWide, 'Wide-scope user should receive the boundary geometry.');

        // Different session/user, low scope: a unit in another region.
        ['user' => $narrowUser, 'unit' => $narrowUnit] = $this->createUserWithUnit(['map']);
        $this->actingAs($narrowUser);

        Cache::increment('maps_version');

        $elsewhere = Region::create(['name' => 'شهرستان دیگر', 'type' => 'county']);
        $narrowUnit->update(['region_id' => $elsewhere->id]);

        $servedToNarrow = [['sentinel' => true]];
        Livewire::test('maps/unit')
            ->set('selectedRegions', [$target->id])
            ->assertDispatched('county-boundaries-loaded', function (string $event, array $data) use (&$servedToNarrow): bool {
                $servedToNarrow = $data['counties'] ?? null;

                return true;
            });

        $this->assertSame([], $servedToNarrow, 'Cache leaked a wide-scope region boundary to a narrow-scope user.');
    }

    /**
     * #1032: geometry and units stay consistent — an out-of-scope selection
     * serves neither boundaries nor units for it.
     */
    public function test_out_of_scope_selection_serves_nothing_consistently(): void
    {
        $this->bustMapCaches();
        ['user' => $user] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        $region = $this->seedRegionAndTypes();
        $region->update(['boundary_id' => $this->createBoundary()]);

        $served = null;
        // Capture the geometry payload right after the region update — a later
        // set() starts a fresh request and the dispatch history is per-request.
        Livewire::test('maps/unit')
            ->set('selectedRegions', [$region->id])
            ->assertDispatched('county-boundaries-loaded', function (string $event, array $data) use (&$served): bool {
                $served = $data['counties'] ?? null;

                return true;
            })
            ->set('selectedCenterTypes', [5])
            ->assertSee('واحدی یافت نشد');

        $this->assertSame([], $served);
    }

    /**
     * Structural pin (#1032): the boundary cache key must include the md5 of
     * the accessible region scope — same shape as `maps/county`. Without this,
     * a behavioral test cannot see a wrongly-shaped key until it leaks live.
     */
    public function test_boundary_cache_key_binds_scope_structurally(): void
    {
        $source = file_get_contents(resource_path('views/livewire/maps/unit.blade.php'));

        $this->assertStringContainsString('accessibleRegionIds', $source);
        // The scope-derived id list feeds both cache keys...
        $this->assertMatchesRegularExpression('/county_boundaries_v.*?md5\(implode\(\',\', \$accessibleRegionIds/s', $source);
        $this->assertMatchesRegularExpression("/unit_map:regions:v.*?md5\(implode\(',', \\\$accessibleRegionIds/s", $source);
        // ...and the query — both layers, same shape as maps/county.
        $this->assertMatchesRegularExpression("/whereIn\('id', \\\$accessibleRegionIds\)/", $source);
    }

    public function test_unit_boundary(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        $region = $this->seedRegionAndTypes();

        // #1032: the region list is scope-derived, so the accessible unit must
        // live in the region for its county toggle to exist at all.
        $unit->update(['region_id' => $region->id]);

        // Initially: region toggles are visible; center types only after selection
        Livewire::test('maps/unit')
            ->assertSee('شهرستان تست')
            ->assertDontSee('مرکز جامع سلامت')  // hidden until region selected
            ->set('selectedRegions', [$region->id])
            ->assertSee('مرکز جامع سلامت')       // now visible
            ->assertSee('مرکز خدمات جامع سلامت')
            ->assertSee('پایگاه سلامت');
    }

    // ==================== Scope filtering ====================

    public function test_scope_filtering(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        $region = $this->seedRegionAndTypes();
        $boundaryId = $this->createBoundary();

        // Accessible unit in selected region with a boundary (required by component)
        $accessibleUnit = Unit::create([
            'name' => 'واحد قابل دسترس',
            'region_id' => $region->id,
            'unit_type_id' => 5,
            'boundary_id' => $boundaryId,
        ]);

        session(['current_unit_id' => $accessibleUnit->id]);

        Livewire::test('maps/unit')
            ->set('selectedRegions', [$region->id])
            ->set('selectedCenterTypes', [5])
            ->assertSee('واحد قابل دسترس');
    }

    // ==================== Filters ====================

    public function test_search_filter(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        Livewire::test('maps/unit')
            ->set('search', 'test')
            ->assertStatus(200);
    }

    public function test_region_filter_dispatches_events(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        $region = $this->seedRegionAndTypes();

        Livewire::test('maps/unit')
            ->set('selectedRegions', [$region->id])
            ->assertDispatched('county-boundaries-loaded')
            ->assertDispatched('units-updated');
    }

    // ==================== Edge cases ====================

    public function test_empty_state_without_units(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        $region = Region::create(['name' => 'شهرستان خالی', 'type' => 'county']);

        DB::table('unit_types')->insert([
            ['id' => 5, 'name' => 'مرکز جامع سلامت', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Select region + center type but no matching units exist
        Livewire::test('maps/unit')
            ->set('selectedRegions', [$region->id])
            ->set('selectedCenterTypes', [5])
            ->assertSee('واحدی یافت نشد');
    }

    public function test_show_help_modal(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        Livewire::test('maps/unit')
            ->assertSet('showHelpModal', false)
            ->call('$refresh')
            ->assertStatus(200);
    }
}
