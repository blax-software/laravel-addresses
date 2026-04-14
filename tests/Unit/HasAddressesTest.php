<?php

namespace Blax\Addresses\Tests\Unit;

use Blax\Addresses\AddressesServiceProvider;
use Blax\Addresses\Enums\AddressLinkType;
use Blax\Addresses\Models\Address;
use Blax\Addresses\Models\AddressLink;
use Blax\Addresses\Models\AddressAssignment;
use Blax\Addresses\Services\AddressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase;
use Workbench\App\Models\Company;
use Workbench\App\Models\Job;
use Workbench\App\Models\User;

class HasAddressesTest extends TestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [AddressesServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../workbench/database/migrations');
    }

    // ─── basic relationship ──────────────────────────────────────

    public function test_model_has_no_addresses_by_default(): void
    {
        $user = User::factory()->create();

        $this->assertCount(0, $user->addresses);
        $this->assertCount(0, $user->addressLinks);
    }

    // ─── addAddress ──────────────────────────────────────────────

    public function test_add_address_creates_address_and_link(): void
    {
        $user = User::factory()->create();

        $link = $user->addAddress([
            'street' => 'Hauptstraße 1',
            'city' => 'Vienna',
            'postal_code' => '1010',
            'country_code' => 'AT',
        ], AddressLinkType::Home);

        $this->assertInstanceOf(AddressLink::class, $link);
        $this->assertInstanceOf(Address::class, $link->address);
        $this->assertEquals('Hauptstraße 1', $link->address->street);
        $this->assertEquals('Vienna', $link->address->city);
        $this->assertEquals(AddressLinkType::Home, $link->type);
    }

    public function test_add_address_with_default_type(): void
    {
        $user = User::factory()->create();

        $link = $user->addAddress([
            'city' => 'London',
            'country_code' => 'GB',
        ]);

        $this->assertEquals(AddressLinkType::Other, $link->type);
    }

    public function test_add_address_with_string_type(): void
    {
        $user = User::factory()->create();

        $link = $user->addAddress([
            'city' => 'Berlin',
        ], 'office');

        $this->assertEquals(AddressLinkType::Office, $link->type);
    }

    public function test_add_address_with_full_details(): void
    {
        $user = User::factory()->create();

        $link = $user->addAddress([
            'street' => '350 Fifth Avenue',
            'street_extra' => 'Suite 3200',
            'building' => 'Empire State Building',
            'floor' => '32',
            'room' => '3201',
            'postal_code' => '10118',
            'city' => 'New York',
            'state' => 'NY',
            'county' => 'New York County',
            'country_code' => 'US',
            'latitude' => 40.7484405,
            'longitude' => -73.9856644,
            'altitude' => 373.0, // approximate AMSL for floor 32
            'notes' => 'Reception on the left',
        ], AddressLinkType::Office, ['label' => 'Empire State Office']);

        $address = $link->address;

        $this->assertEquals('Empire State Office', $link->label);
        $this->assertEquals('350 Fifth Avenue', $address->street);
        $this->assertEquals('Suite 3200', $address->street_extra);
        $this->assertEquals('Empire State Building', $address->building);
        $this->assertEquals('32', $address->floor);
        $this->assertEquals('3201', $address->room);
        $this->assertEquals('10118', $address->postal_code);
        $this->assertEquals('New York', $address->city);
        $this->assertEquals('NY', $address->state);
        $this->assertEquals('New York County', $address->county);
        $this->assertEquals('US', $address->country_code);
        $this->assertEqualsWithDelta(40.7484405, $address->latitude, 0.0001);
        $this->assertEqualsWithDelta(-73.9856644, $address->longitude, 0.0001);
        $this->assertEqualsWithDelta(373.0, $address->altitude, 0.01);
        $this->assertEquals('Reception on the left', $address->notes);
    }

    public function test_add_address_coordinates_only(): void
    {
        $user = User::factory()->create();

        $link = $user->addAddress([
            'latitude' => 47.0707,
            'longitude' => 15.4395,
            'altitude' => 853.2,
        ], AddressLinkType::PointOfInterest);

        $address = $link->address;

        $this->assertTrue($address->hasCoordinates());
        $this->assertTrue($address->hasAltitude());
        $this->assertNull($address->street);
        $this->assertNull($address->city);
        $this->assertEquals(AddressLinkType::PointOfInterest, $link->type);
    }

    // ─── linkAddress ─────────────────────────────────────────────

    public function test_link_existing_address(): void
    {
        $user = User::factory()->create();
        $address = Address::create([
            'street' => 'Reusable Street 5',
            'city' => 'Graz',
            'country_code' => 'AT',
        ]);

        $link = $user->linkAddress($address, AddressLinkType::Home);

        $this->assertEquals($address->id, $link->address_id);
        $this->assertTrue($user->hasAddresses());
    }

    public function test_link_address_by_id(): void
    {
        $user = User::factory()->create();
        $address = Address::create(['city' => 'Salzburg', 'country_code' => 'AT']);

        $link = $user->linkAddress($address->id, AddressLinkType::Billing);

        $this->assertEquals($address->id, $link->address_id);
        $this->assertEquals(AddressLinkType::Billing, $link->type);
    }

    public function test_same_address_linked_multiple_times_with_different_types(): void
    {
        $user = User::factory()->create();
        $address = Address::create(['city' => 'Linz', 'country_code' => 'AT']);

        $user->linkAddress($address, AddressLinkType::Office);
        $user->linkAddress($address, AddressLinkType::Billing);

        $this->assertCount(2, $user->addressLinks()->get());
        $this->assertCount(2, $user->addresses()->get());
    }

    public function test_same_address_linked_to_different_models(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'ACME Corp']);
        $address = Address::create(['city' => 'Innsbruck', 'country_code' => 'AT']);

        $user->linkAddress($address, AddressLinkType::Home);
        $company->linkAddress($address, AddressLinkType::Headquarters);

        $this->assertCount(2, $address->links()->get());
        $this->assertTrue($user->hasAddresses());
        $this->assertTrue($company->hasAddresses());
    }

    // ─── pivot data ──────────────────────────────────────────────

    public function test_link_with_active_from_and_active_until(): void
    {
        $user = User::factory()->create();

        $from = now()->subDay();
        $until = now()->addYear();

        $link = $user->addAddress(
            ['city' => 'Munich', 'country_code' => 'DE'],
            AddressLinkType::Temporary,
            [
                'active_from' => $from,
                'active_until' => $until,
            ]
        );

        $this->assertNotNull($link->active_from);
        $this->assertNotNull($link->active_until);
        $this->assertTrue($link->isActive());
    }

    public function test_expired_link(): void
    {
        $user = User::factory()->create();

        $link = $user->addAddress(
            ['city' => 'Paris', 'country_code' => 'FR'],
            AddressLinkType::Temporary,
            [
                'active_from' => now()->subYear(),
                'active_until' => now()->subDay(),
            ]
        );

        $this->assertFalse($link->isActive());
    }

    public function test_link_with_meta(): void
    {
        $user = User::factory()->create();

        $link = $user->addAddress(
            ['city' => 'Tokyo', 'country_code' => 'JP'],
            AddressLinkType::Office,
            [
                'meta' => ['department' => 'Engineering', 'access_code' => 'A-123'],
            ]
        );

        $meta = $link->getMeta();
        $this->assertEquals('Engineering', $meta->department);
        $this->assertEquals('A-123', $meta->access_code);
    }

    public function test_link_with_custom_label(): void
    {
        $user = User::factory()->create();

        $link = $user->addAddress(
            ['city' => 'Rome', 'country_code' => 'IT'],
            AddressLinkType::Other,
            ['label' => 'Aunt Maria\'s house']
        );

        $this->assertEquals('Aunt Maria\'s house', $link->label);
        $this->assertEquals(AddressLinkType::Other, $link->type);
    }

    public function test_link_with_is_primary(): void
    {
        $user = User::factory()->create();

        $link = $user->addAddress(
            ['city' => 'Madrid', 'country_code' => 'ES'],
            AddressLinkType::Home,
            ['is_primary' => true]
        );

        $this->assertTrue($link->is_primary);
    }

    // ─── querying ────────────────────────────────────────────────

    public function test_addresses_of_type(): void
    {
        $user = User::factory()->create();
        $user->addAddress(['city' => 'A'], AddressLinkType::Home);
        $user->addAddress(['city' => 'B'], AddressLinkType::Office);
        $user->addAddress(['city' => 'C'], AddressLinkType::Home);

        $homes = $user->addressesOfType(AddressLinkType::Home);
        $offices = $user->addressesOfType(AddressLinkType::Office);

        $this->assertCount(2, $homes);
        $this->assertCount(1, $offices);
    }

    public function test_addresses_of_type_with_string(): void
    {
        $user = User::factory()->create();
        $user->addAddress(['city' => 'A'], AddressLinkType::Billing);

        $result = $user->addressesOfType('billing');
        $this->assertCount(1, $result);
    }

    public function test_has_address_of_type(): void
    {
        $user = User::factory()->create();
        $user->addAddress(['city' => 'A'], AddressLinkType::Shipping);

        $this->assertTrue($user->hasAddressOfType(AddressLinkType::Shipping));
        $this->assertFalse($user->hasAddressOfType(AddressLinkType::Billing));
    }

    public function test_has_addresses(): void
    {
        $user = User::factory()->create();
        $this->assertFalse($user->hasAddresses());

        $user->addAddress(['city' => 'X']);
        $this->assertTrue($user->hasAddresses());
    }

    public function test_primary_address(): void
    {
        $user = User::factory()->create();
        $user->addAddress(['city' => 'First'], AddressLinkType::Home);
        $user->addAddress(['city' => 'Second'], AddressLinkType::Home, ['is_primary' => true]);

        $primary = $user->primaryAddress(AddressLinkType::Home);
        $this->assertNotNull($primary);
        $this->assertEquals('Second', $primary->city);
    }

    public function test_primary_address_returns_null_when_none(): void
    {
        $user = User::factory()->create();
        $user->addAddress(['city' => 'NoPrimary'], AddressLinkType::Home);

        $this->assertNull($user->primaryAddress(AddressLinkType::Home));
    }

    public function test_active_address_links(): void
    {
        $user = User::factory()->create();

        // Active link
        $user->addAddress(['city' => 'Active'], AddressLinkType::Home, [
            'active_from' => now()->subDay(),
            'active_until' => now()->addYear(),
        ]);

        // Expired link
        $user->addAddress(['city' => 'Expired'], AddressLinkType::Office, [
            'active_from' => now()->subYear(),
            'active_until' => now()->subDay(),
        ]);

        // No temporal constraints (always active)
        $user->addAddress(['city' => 'Always'], AddressLinkType::Billing);

        $active = $user->activeAddressLinks();
        $this->assertCount(2, $active);

        $cities = $active->pluck('address.city')->sort()->values()->toArray();
        $this->assertEquals(['Active', 'Always'], $cities);
    }

    // ─── removing / detaching ────────────────────────────────────

    public function test_remove_address_link(): void
    {
        $user = User::factory()->create();
        $link = $user->addAddress(['city' => 'ToRemove'], AddressLinkType::Home);

        $this->assertTrue($user->removeAddressLink($link->id));
        $this->assertFalse($user->hasAddresses());

        // Address record still exists
        $this->assertDatabaseHas('addresses', ['city' => 'ToRemove']);
    }

    public function test_detach_address(): void
    {
        $user = User::factory()->create();
        $address = Address::create(['city' => 'Shared', 'country_code' => 'AT']);
        $user->linkAddress($address, AddressLinkType::Home);
        $user->linkAddress($address, AddressLinkType::Billing);

        $removed = $user->detachAddress($address);

        $this->assertEquals(2, $removed);
        $this->assertFalse($user->hasAddresses());
        // Address record still exists
        $this->assertDatabaseHas('addresses', ['city' => 'Shared']);
    }

    public function test_detach_all_addresses(): void
    {
        $user = User::factory()->create();
        $user->addAddress(['city' => 'A'], AddressLinkType::Home);
        $user->addAddress(['city' => 'B'], AddressLinkType::Office);
        $user->addAddress(['city' => 'C'], AddressLinkType::Billing);

        $removed = $user->detachAllAddresses();

        $this->assertEquals(3, $removed);
        $this->assertFalse($user->hasAddresses());
    }

    // ─── set primary ─────────────────────────────────────────────

    public function test_set_primary_address_link(): void
    {
        $user = User::factory()->create();
        $link1 = $user->addAddress(['city' => 'First'], AddressLinkType::Home, ['is_primary' => true]);
        $link2 = $user->addAddress(['city' => 'Second'], AddressLinkType::Home);

        $result = $user->setPrimaryAddressLink($link2->id);

        $this->assertTrue($result);
        $this->assertFalse($link1->fresh()->is_primary);
        $this->assertTrue($link2->fresh()->is_primary);
    }

    public function test_set_primary_returns_false_for_nonexistent_link(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->setPrimaryAddressLink(999));
    }

    public function test_set_primary_does_not_affect_other_types(): void
    {
        $user = User::factory()->create();
        $homeLink = $user->addAddress(['city' => 'Home'], AddressLinkType::Home, ['is_primary' => true]);
        $officeLink = $user->addAddress(['city' => 'Office'], AddressLinkType::Office);

        $user->setPrimaryAddressLink($officeLink->id);

        // Home primary should remain untouched
        $this->assertTrue($homeLink->fresh()->is_primary);
        $this->assertTrue($officeLink->fresh()->is_primary);
    }

    // ─── Address model ───────────────────────────────────────────

    public function test_address_has_coordinates(): void
    {
        $address = Address::create([
            'latitude' => 48.2082,
            'longitude' => 16.3738,
        ]);

        $this->assertTrue($address->hasCoordinates());
    }

    public function test_address_without_coordinates(): void
    {
        $address = Address::create([
            'city' => 'NoCoords',
        ]);

        $this->assertFalse($address->hasCoordinates());
        $this->assertFalse($address->hasAltitude());
    }

    public function test_address_to_coordinates(): void
    {
        $address = Address::create([
            'latitude' => 47.0707,
            'longitude' => 15.4395,
            'altitude' => 353.0,
        ]);

        $coords = $address->toCoordinates();

        $this->assertArrayHasKey('latitude', $coords);
        $this->assertArrayHasKey('longitude', $coords);
        $this->assertArrayHasKey('altitude', $coords);
        $this->assertEqualsWithDelta(353.0, $coords['altitude'], 0.01);
    }

    public function test_address_formatted_attribute(): void
    {
        $address = Address::create([
            'street' => 'Rainerstraße 4',
            'postal_code' => '4020',
            'city' => 'Linz',
            'country_code' => 'AT',
        ]);

        $formatted = $address->formatted;

        $this->assertStringContainsString('Rainerstraße 4', $formatted);
        $this->assertStringContainsString('4020', $formatted);
        $this->assertStringContainsString('Linz', $formatted);
        $this->assertStringContainsString('AT', $formatted);
    }

    public function test_address_meta(): void
    {
        $address = Address::create([
            'city' => 'MetaCity',
            'meta' => ['plus_code' => '8FWR39JJ+XX', 'timezone' => 'Europe/Vienna'],
        ]);

        $meta = $address->getMeta();
        $this->assertEquals('8FWR39JJ+XX', $meta->plus_code);
        $this->assertEquals('Europe/Vienna', $meta->timezone);
    }

    public function test_address_soft_delete(): void
    {
        $address = Address::create(['city' => 'SoftDelete']);
        $address->delete();

        $this->assertSoftDeleted('addresses', ['city' => 'SoftDelete']);
        $this->assertNull(Address::find($address->id));
        $this->assertNotNull(Address::withTrashed()->find($address->id));
    }

    // ─── AddressLink model scopes ────────────────────────────────

    public function test_address_link_scope_active(): void
    {
        $user = User::factory()->create();

        $user->addAddress(['city' => 'Active'], AddressLinkType::Home, [
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);
        $user->addAddress(['city' => 'Expired'], AddressLinkType::Office, [
            'active_until' => now()->subDay(),
        ]);

        $active = $user->addressLinks()->active()->get();
        $this->assertCount(1, $active);
        $this->assertEquals('Active', $active->first()->address->city);
    }

    public function test_address_link_scope_expired(): void
    {
        $user = User::factory()->create();

        $user->addAddress(['city' => 'Current'], AddressLinkType::Home);
        $user->addAddress(['city' => 'Old'], AddressLinkType::Office, [
            'active_until' => now()->subDay(),
        ]);

        $expired = $user->addressLinks()->expired()->get();
        $this->assertCount(1, $expired);
    }

    public function test_address_link_scope_of_type(): void
    {
        $user = User::factory()->create();

        $user->addAddress(['city' => 'A'], AddressLinkType::Home);
        $user->addAddress(['city' => 'B'], AddressLinkType::Office);
        $user->addAddress(['city' => 'C'], AddressLinkType::Home);

        $homes = $user->addressLinks()->ofType(AddressLinkType::Home)->get();
        $this->assertCount(2, $homes);
    }

    public function test_address_link_scope_primary(): void
    {
        $user = User::factory()->create();

        $user->addAddress(['city' => 'Primary'], AddressLinkType::Home, ['is_primary' => true]);
        $user->addAddress(['city' => 'Secondary'], AddressLinkType::Home);

        $primaries = $user->addressLinks()->primary()->get();
        $this->assertCount(1, $primaries);
    }

    // ─── AddressLinkType enum ────────────────────────────────────

    public function test_enum_values_are_strings(): void
    {
        $this->assertIsString(AddressLinkType::Home->value);
        $this->assertEquals('home', AddressLinkType::Home->value);
        $this->assertEquals('office', AddressLinkType::Office->value);
        $this->assertEquals('billing', AddressLinkType::Billing->value);
    }

    public function test_enum_labels(): void
    {
        $this->assertEquals('Home', AddressLinkType::Home->label());
        $this->assertEquals('Office', AddressLinkType::Office->label());
        $this->assertEquals('Point of Interest', AddressLinkType::PointOfInterest->label());
        $this->assertEquals('Secondary Residence', AddressLinkType::SecondaryResidence->label());
    }

    public function test_enum_can_be_cast_from_string(): void
    {
        $type = AddressLinkType::from('shipping');
        $this->assertEquals(AddressLinkType::Shipping, $type);
    }

    // ─── polymorphic: Company model ──────────────────────────────

    public function test_company_can_have_addresses(): void
    {
        $company = Company::create(['name' => 'Test Corp']);

        $link = $company->addAddress([
            'street' => 'Business Ave 42',
            'city' => 'Zurich',
            'country_code' => 'CH',
        ], AddressLinkType::Headquarters);

        $this->assertTrue($company->hasAddresses());
        $this->assertEquals(AddressLinkType::Headquarters, $link->type);
    }

    public function test_address_links_back_to_addressable(): void
    {
        $user = User::factory()->create();
        $link = $user->addAddress(['city' => 'Backref'], AddressLinkType::Home);

        $freshLink = AddressLink::find($link->id);
        $this->assertInstanceOf(User::class, $freshLink->addressable);
        $this->assertEquals($user->id, $freshLink->addressable->id);
    }

    // ─── negative altitude (below sea level) ─────────────────────

    public function test_negative_altitude(): void
    {
        $address = Address::create([
            'latitude' => 31.5,
            'longitude' => 35.5,
            'altitude' => -430.5,
            'country_code' => 'IL',
        ]);

        $this->assertEqualsWithDelta(-430.5, $address->altitude, 0.01);
        $this->assertTrue($address->hasAltitude());
    }

    // ─── cascade delete ──────────────────────────────────────────

    public function test_deleting_address_cascades_to_links(): void
    {
        $user = User::factory()->create();
        $link = $user->addAddress(['city' => 'Cascade'], AddressLinkType::Home);
        $addressId = $link->address_id;

        // Force-delete the address to trigger cascade
        Address::withTrashed()->find($addressId)->forceDelete();

        $this->assertDatabaseMissing('address_links', ['address_id' => $addressId]);
    }

    // ═══════════════════════════════════════════════════════════════
    // AddressService
    // ═══════════════════════════════════════════════════════════════

    protected function service(): AddressService
    {
        return app(AddressService::class);
    }

    // ─── helper function ─────────────────────────────────────────

    public function test_address_helper_returns_service(): void
    {
        $this->assertInstanceOf(AddressService::class, address());
    }

    // ─── haversine / distance ────────────────────────────────────

    public function test_haversine_same_point_returns_zero(): void
    {
        $distance = $this->service()->haversine(48.2082, 16.3738, 48.2082, 16.3738);

        $this->assertEquals(0.0, $distance);
    }

    public function test_haversine_known_distance(): void
    {
        // Vienna (48.2082, 16.3738) → Graz (47.0707, 15.4395) ≈ 145 km
        $distance = $this->service()->haversine(48.2082, 16.3738, 47.0707, 15.4395);

        $this->assertEqualsWithDelta(145.0, $distance, 5.0);
    }

    public function test_haversine_miles(): void
    {
        $km = $this->service()->haversine(48.2082, 16.3738, 47.0707, 15.4395, 'km');
        $mi = $this->service()->haversine(48.2082, 16.3738, 47.0707, 15.4395, 'mi');

        // 1 km ≈ 0.621 mi
        $this->assertLessThan($km, $mi);
    }

    public function test_distance_between_addresses(): void
    {
        $vienna = Address::create(['latitude' => 48.2082, 'longitude' => 16.3738]);
        $graz = Address::create(['latitude' => 47.0707, 'longitude' => 15.4395]);

        $distance = $this->service()->distanceBetween($vienna, $graz);

        $this->assertNotNull($distance);
        $this->assertEqualsWithDelta(145.0, $distance, 5.0);
    }

    public function test_distance_between_returns_null_without_coordinates(): void
    {
        $a = Address::create(['city' => 'A']);
        $b = Address::create(['city' => 'B']);

        $this->assertNull($this->service()->distanceBetween($a, $b));
    }

    public function test_altitude_difference(): void
    {
        $low = Address::create(['latitude' => 48.0, 'longitude' => 16.0, 'altitude' => 170.0]);
        $high = Address::create(['latitude' => 47.0, 'longitude' => 15.0, 'altitude' => 850.0]);

        $diff = $this->service()->altitudeDifference($low, $high);

        $this->assertEqualsWithDelta(680.0, $diff, 0.01);
    }

    public function test_altitude_difference_returns_null_when_missing(): void
    {
        $a = Address::create(['latitude' => 48.0, 'longitude' => 16.0]);
        $b = Address::create(['latitude' => 47.0, 'longitude' => 15.0, 'altitude' => 850.0]);

        $this->assertNull($this->service()->altitudeDifference($a, $b));
    }

    // ─── proximity ───────────────────────────────────────────────

    public function test_nearby_finds_addresses_within_radius(): void
    {
        // Vienna centre
        Address::create(['city' => 'Centre', 'latitude' => 48.2082, 'longitude' => 16.3738]);
        // ~5 km away
        Address::create(['city' => 'Near', 'latitude' => 48.24, 'longitude' => 16.40]);
        // ~145 km away (Graz)
        Address::create(['city' => 'Far', 'latitude' => 47.0707, 'longitude' => 15.4395]);

        $results = $this->service()->nearby(48.2082, 16.3738, 20);

        $cities = $results->pluck('city')->toArray();
        $this->assertContains('Centre', $cities);
        $this->assertContains('Near', $cities);
        $this->assertNotContains('Far', $cities);
    }

    public function test_nearby_results_are_sorted_by_distance(): void
    {
        Address::create(['city' => 'Far', 'latitude' => 48.30, 'longitude' => 16.50]);
        Address::create(['city' => 'Near', 'latitude' => 48.21, 'longitude' => 16.38]);
        Address::create(['city' => 'Mid', 'latitude' => 48.25, 'longitude' => 16.42]);

        $results = $this->service()->nearby(48.2082, 16.3738, 50);

        $cities = $results->pluck('city')->toArray();
        $this->assertEquals('Near', $cities[0]);
    }

    public function test_nearby_results_have_distance_attribute(): void
    {
        Address::create(['city' => 'A', 'latitude' => 48.21, 'longitude' => 16.38]);

        $results = $this->service()->nearby(48.2082, 16.3738, 50);

        $this->assertNotEmpty($results);
        $this->assertIsFloat($results->first()->distance);
    }

    public function test_nearby_address_excludes_self(): void
    {
        $ref = Address::create(['city' => 'Ref', 'latitude' => 48.2082, 'longitude' => 16.3738]);
        Address::create(['city' => 'Buddy', 'latitude' => 48.21, 'longitude' => 16.38]);

        $results = $this->service()->nearbyAddress($ref, 50);

        $ids = $results->pluck('id')->toArray();
        $this->assertNotContains($ref->id, $ids);
        $this->assertCount(1, $results);
    }

    public function test_nearby_address_include_self(): void
    {
        $ref = Address::create(['city' => 'Ref', 'latitude' => 48.2082, 'longitude' => 16.3738]);
        Address::create(['city' => 'Buddy', 'latitude' => 48.21, 'longitude' => 16.38]);

        $results = $this->service()->nearbyAddress($ref, 50, 'km', false);

        $this->assertCount(2, $results);
    }

    public function test_nearby_address_without_coordinates_returns_empty(): void
    {
        $ref = Address::create(['city' => 'NoCoords']);

        $results = $this->service()->nearbyAddress($ref, 50);

        $this->assertEmpty($results);
    }

    public function test_closest_address(): void
    {
        Address::create(['city' => 'Far', 'latitude' => 47.0707, 'longitude' => 15.4395]);
        Address::create(['city' => 'Close', 'latitude' => 48.21, 'longitude' => 16.38]);

        $closest = $this->service()->closest(48.2082, 16.3738);

        $this->assertNotNull($closest);
        $this->assertEquals('Close', $closest->city);
    }

    public function test_closest_returns_null_when_no_addresses(): void
    {
        $this->assertNull($this->service()->closest(48.0, 16.0));
    }

    // ─── bounding box ────────────────────────────────────────────

    public function test_bounding_box(): void
    {
        $box = $this->service()->boundingBox(48.2082, 16.3738, 10);

        $this->assertLessThan(48.2082, $box['minLat']);
        $this->assertGreaterThan(48.2082, $box['maxLat']);
        $this->assertLessThan(16.3738, $box['minLng']);
        $this->assertGreaterThan(16.3738, $box['maxLng']);
    }

    // ─── duplicates ──────────────────────────────────────────────

    public function test_find_duplicates(): void
    {
        $addr = Address::create(['street' => 'Hauptplatz 1', 'city' => 'Linz', 'postal_code' => '4020', 'country_code' => 'AT']);
        $dup = Address::create(['street' => 'Hauptplatz 1', 'city' => 'Linz', 'postal_code' => '4020', 'country_code' => 'AT']);
        Address::create(['street' => 'Nebenstraße 5', 'city' => 'Linz', 'postal_code' => '4020', 'country_code' => 'AT']);

        $duplicates = $this->service()->findDuplicates($addr);

        $this->assertCount(1, $duplicates);
        $this->assertEquals($dup->id, $duplicates->first()->id);
    }

    public function test_merge_reassigns_links_and_soft_deletes(): void
    {
        $user = User::factory()->create();
        $target = Address::create(['city' => 'Target']);
        $duplicate = Address::create(['city' => 'Duplicate']);

        $user->linkAddress($duplicate, AddressLinkType::Home);
        $user->linkAddress($duplicate, AddressLinkType::Office);

        $reassigned = $this->service()->merge($target, $duplicate);

        $this->assertEquals(2, $reassigned);
        $this->assertSoftDeleted('addresses', ['id' => $duplicate->id]);
        $this->assertEquals(2, $user->addressLinks()->where('address_id', $target->id)->count());
    }

    // ─── query builders ──────────────────────────────────────────

    public function test_in_country(): void
    {
        Address::create(['city' => 'Vienna', 'country_code' => 'AT']);
        Address::create(['city' => 'Berlin', 'country_code' => 'DE']);

        $result = $this->service()->inCountry('AT')->get();

        $this->assertCount(1, $result);
        $this->assertEquals('Vienna', $result->first()->city);
    }

    public function test_in_city(): void
    {
        Address::create(['city' => 'Vienna', 'country_code' => 'AT']);
        Address::create(['city' => 'Vienna', 'country_code' => 'US']); // Vienna, Virginia

        $all = $this->service()->inCity('Vienna')->get();
        $atOnly = $this->service()->inCity('Vienna', 'AT')->get();

        $this->assertCount(2, $all);
        $this->assertCount(1, $atOnly);
    }

    public function test_in_postal_code(): void
    {
        Address::create(['postal_code' => '1010', 'country_code' => 'AT']);
        Address::create(['postal_code' => '1010', 'country_code' => 'DE']);

        $result = $this->service()->inPostalCode('1010', 'AT')->get();

        $this->assertCount(1, $result);
    }

    public function test_with_coordinates(): void
    {
        Address::create(['city' => 'HasCoords', 'latitude' => 48.0, 'longitude' => 16.0]);
        Address::create(['city' => 'NoCoords']);

        $result = $this->service()->withCoordinates()->get();

        $this->assertCount(1, $result);
        $this->assertEquals('HasCoords', $result->first()->city);
    }

    // ─── formatting ──────────────────────────────────────────────

    public function test_format_single_line(): void
    {
        $addr = Address::create([
            'street' => 'Rainerstraße 4',
            'postal_code' => '4020',
            'city' => 'Linz',
            'country_code' => 'AT',
        ]);

        $formatted = $this->service()->format($addr);

        $this->assertStringContainsString('Rainerstraße 4', $formatted);
        $this->assertStringContainsString('4020', $formatted);
        $this->assertStringContainsString('Linz', $formatted);
    }

    public function test_format_with_custom_separator(): void
    {
        $addr = Address::create(['street' => 'A', 'city' => 'B']);

        $formatted = $this->service()->format($addr, ' | ');

        $this->assertEquals('A | B', $formatted);
    }

    public function test_format_multiline(): void
    {
        $addr = Address::create([
            'street' => '350 Fifth Avenue',
            'street_extra' => 'Suite 3200',
            'building' => 'Empire State Building',
            'floor' => '32',
            'room' => '3201',
            'postal_code' => '10118',
            'city' => 'New York',
            'state' => 'NY',
            'country_code' => 'US',
        ]);

        $lines = explode("\n", $this->service()->formatMultiline($addr));

        $this->assertStringContainsString('350 Fifth Avenue', $lines[0]);
        $this->assertStringContainsString('Suite 3200', $lines[0]);
        $this->assertStringContainsString('Empire State Building', $lines[1]);
        $this->assertStringContainsString('Floor 32', $lines[1]);
        $this->assertStringContainsString('10118 New York', $lines[2]);
        $this->assertEquals('US', $lines[3]);
    }

    public function test_format_coordinates(): void
    {
        $addr = Address::create(['latitude' => 48.2082, 'longitude' => 16.3738, 'altitude' => 171.0]);

        $result = $this->service()->formatCoordinates($addr);

        $this->assertStringContainsString('N', $result);
        $this->assertStringContainsString('E', $result);
        $this->assertStringContainsString('AMSL', $result);
    }

    public function test_format_coordinates_southern_western(): void
    {
        $addr = Address::create(['latitude' => -33.8688, 'longitude' => -151.2093]);

        $result = $this->service()->formatCoordinates($addr);

        $this->assertStringContainsString('S', $result);
        // Note: -151 is actually W
        $this->assertStringContainsString('W', $result);
    }

    public function test_format_coordinates_returns_null_without_coords(): void
    {
        $addr = Address::create(['city' => 'NoCoordsCity']);

        $this->assertNull($this->service()->formatCoordinates($addr));
    }

    // ─── coordinate conversion ───────────────────────────────────

    public function test_dms_to_decimal(): void
    {
        // 48°12'29.5"N → 48.2082
        $result = $this->service()->dmsToDecimal(48, 12, 29.52, 'N');

        $this->assertEqualsWithDelta(48.2082, $result, 0.001);
    }

    public function test_dms_to_decimal_south(): void
    {
        $result = $this->service()->dmsToDecimal(33, 52, 7.7, 'S');

        $this->assertLessThan(0, $result);
        $this->assertEqualsWithDelta(-33.8688, $result, 0.01);
    }

    public function test_decimal_to_dms_latitude(): void
    {
        $result = $this->service()->decimalToDms(48.2082, 'lat');

        $this->assertEquals(48, $result['degrees']);
        $this->assertEquals(12, $result['minutes']);
        $this->assertEquals('N', $result['direction']);
    }

    public function test_decimal_to_dms_longitude_west(): void
    {
        $result = $this->service()->decimalToDms(-73.9856, 'lng');

        $this->assertEquals(73, $result['degrees']);
        $this->assertEquals('W', $result['direction']);
    }

    public function test_dms_roundtrip(): void
    {
        $original = 48.2082;
        $dms = $this->service()->decimalToDms($original, 'lat');
        $back = $this->service()->dmsToDecimal($dms['degrees'], $dms['minutes'], $dms['seconds'], $dms['direction']);

        $this->assertEqualsWithDelta($original, $back, 0.0001);
    }

    // ═══════════════════════════════════════════════════════════════
    // AddressAssignment — assign an AddressLink to another model
    // ═══════════════════════════════════════════════════════════════

    private function createJobWithAssignment(string $role = 'pickup'): array
    {
        $user = User::factory()->create();
        $link = $user->addAddress([
            'street' => 'Hauptstraße 1',
            'city' => 'Vienna',
            'country_code' => 'AT',
        ], AddressLinkType::Office);

        $job = Job::create(['title' => 'Piano Move']);
        $assignment = $job->assignAddressLink($link, $role);

        return compact('user', 'link', 'job', 'assignment');
    }

    // ─── assign address link ─────────────────────────────────────

    public function test_assign_address_link_creates_assignment(): void
    {
        ['job' => $job, 'assignment' => $assignment, 'link' => $link] = $this->createJobWithAssignment();

        $this->assertInstanceOf(AddressAssignment::class, $assignment);
        $this->assertEquals($link->id, $assignment->address_link_id);
        $this->assertEquals('pickup', $assignment->role);
        $this->assertEquals($job->getMorphClass(), $assignment->assignable_type);
        $this->assertEquals($job->id, $assignment->assignable_id);
    }

    public function test_assign_address_link_by_id(): void
    {
        $user = User::factory()->create();
        $link = $user->addAddress(['city' => 'Berlin'], AddressLinkType::Office);

        $job = Job::create(['title' => 'Delivery']);
        $assignment = $job->assignAddressLink($link->id, 'delivery');

        $this->assertEquals($link->id, $assignment->address_link_id);
        $this->assertEquals('delivery', $assignment->role);
    }

    public function test_assign_address_link_with_label_and_meta(): void
    {
        $user = User::factory()->create();
        $link = $user->addAddress(['city' => 'Vienna'], AddressLinkType::Home);

        $job = Job::create(['title' => 'Move']);
        $assignment = $job->assignAddressLink($link, 'origin', [
            'label' => 'Customer Home',
            'meta' => ['floor_access' => 'elevator'],
        ]);

        $this->assertEquals('Customer Home', $assignment->label);
        $this->assertEquals('elevator', $assignment->meta->floor_access);
    }

    public function test_assign_address_link_without_role(): void
    {
        $user = User::factory()->create();
        $link = $user->addAddress(['city' => 'Graz']);

        $job = Job::create(['title' => 'Task']);
        $assignment = $job->assignAddressLink($link);

        $this->assertNull($assignment->role);
    }

    public function test_assignment_loads_address_link_and_address(): void
    {
        ['assignment' => $assignment] = $this->createJobWithAssignment();

        $this->assertTrue($assignment->relationLoaded('addressLink'));
        $this->assertTrue($assignment->addressLink->relationLoaded('address'));
        $this->assertEquals('Vienna', $assignment->addressLink->address->city);
    }

    // ─── relationships ───────────────────────────────────────────

    public function test_address_assignments_morphmany(): void
    {
        ['job' => $job] = $this->createJobWithAssignment();

        $this->assertCount(1, $job->addressAssignments);
    }

    public function test_assignment_belongs_to_address_link(): void
    {
        ['assignment' => $assignment, 'link' => $link] = $this->createJobWithAssignment();

        $freshAssignment = AddressAssignment::find($assignment->id);
        $this->assertEquals($link->id, $freshAssignment->addressLink->id);
    }

    public function test_assignment_address_shortcut(): void
    {
        ['assignment' => $assignment] = $this->createJobWithAssignment();

        $freshAssignment = AddressAssignment::with('address')->find($assignment->id);
        $this->assertNotNull($freshAssignment->address);
        $this->assertEquals('Vienna', $freshAssignment->address->city);
    }

    public function test_assignment_assignable_morphto(): void
    {
        ['assignment' => $assignment, 'job' => $job] = $this->createJobWithAssignment();

        $fresh = AddressAssignment::find($assignment->id);
        $this->assertInstanceOf(Job::class, $fresh->assignable);
        $this->assertEquals($job->id, $fresh->assignable->id);
    }

    public function test_address_link_has_many_assignments(): void
    {
        $user = User::factory()->create();
        $link = $user->addAddress(['city' => 'Vienna'], AddressLinkType::Office);

        $job1 = Job::create(['title' => 'Job 1']);
        $job2 = Job::create(['title' => 'Job 2']);

        $job1->assignAddressLink($link, 'pickup');
        $job2->assignAddressLink($link, 'delivery');

        $this->assertCount(2, $link->fresh()->assignments);
    }

    // ─── multiple assignments on one model ───────────────────────

    public function test_multiple_assignments_on_one_model(): void
    {
        $user = User::factory()->create();
        $officeLink = $user->addAddress(['city' => 'Vienna'], AddressLinkType::Office);
        $homeLink = $user->addAddress(['city' => 'Graz'], AddressLinkType::Home);

        $job = Job::create(['title' => 'Piano Move']);
        $job->assignAddressLink($officeLink, 'pickup');
        $job->assignAddressLink($homeLink, 'delivery');

        $this->assertCount(2, $job->fresh()->addressAssignments);
    }

    // ─── removing assignments ────────────────────────────────────

    public function test_remove_address_assignment(): void
    {
        ['job' => $job, 'assignment' => $assignment] = $this->createJobWithAssignment();

        $this->assertTrue($job->removeAddressAssignment($assignment->id));
        $this->assertCount(0, $job->fresh()->addressAssignments);
    }

    public function test_remove_assignments_for_role(): void
    {
        $user = User::factory()->create();
        $link = $user->addAddress(['city' => 'Vienna'], AddressLinkType::Office);
        $link2 = $user->addAddress(['city' => 'Graz'], AddressLinkType::Home);

        $job = Job::create(['title' => 'Move']);
        $job->assignAddressLink($link, 'pickup');
        $job->assignAddressLink($link2, 'pickup');
        $job->assignAddressLink($link, 'delivery');

        $removed = $job->removeAssignmentsForRole('pickup');

        $this->assertEquals(2, $removed);
        $this->assertCount(1, $job->fresh()->addressAssignments);
    }

    public function test_remove_all_address_assignments(): void
    {
        $user = User::factory()->create();
        $link = $user->addAddress(['city' => 'Vienna'], AddressLinkType::Office);

        $job = Job::create(['title' => 'Move']);
        $job->assignAddressLink($link, 'pickup');
        $job->assignAddressLink($link, 'delivery');

        $removed = $job->removeAllAddressAssignments();

        $this->assertEquals(2, $removed);
        $this->assertCount(0, $job->fresh()->addressAssignments);
    }

    // ─── cascade delete ──────────────────────────────────────────

    public function test_deleting_address_link_cascades_to_assignments(): void
    {
        ['link' => $link, 'assignment' => $assignment] = $this->createJobWithAssignment();

        $link->delete();

        $this->assertNull(AddressAssignment::find($assignment->id));
    }

    public function test_deleting_address_cascades_through_link_to_assignments(): void
    {
        ['link' => $link, 'assignment' => $assignment] = $this->createJobWithAssignment();

        $link->address->forceDelete();

        $this->assertNull(AddressLink::find($link->id));
        $this->assertNull(AddressAssignment::find($assignment->id));
    }

    // ─── querying ────────────────────────────────────────────────

    public function test_address_assignment_for_role(): void
    {
        ['job' => $job] = $this->createJobWithAssignment('pickup');

        $result = $job->addressAssignmentForRole('pickup');

        $this->assertInstanceOf(AddressAssignment::class, $result);
        $this->assertEquals('pickup', $result->role);
        $this->assertTrue($result->relationLoaded('addressLink'));
    }

    public function test_address_assignment_for_role_returns_null_when_missing(): void
    {
        ['job' => $job] = $this->createJobWithAssignment('pickup');

        $this->assertNull($job->addressAssignmentForRole('delivery'));
    }

    public function test_address_assignments_for_role(): void
    {
        $user = User::factory()->create();
        $link1 = $user->addAddress(['city' => 'Vienna'], AddressLinkType::Office);
        $link2 = $user->addAddress(['city' => 'Graz'], AddressLinkType::Home);

        $job = Job::create(['title' => 'Move']);
        $job->assignAddressLink($link1, 'stop');
        $job->assignAddressLink($link2, 'stop');
        $job->assignAddressLink($link1, 'delivery');

        $stops = $job->addressAssignmentsForRole('stop');

        $this->assertCount(2, $stops);
    }

    public function test_assigned_address_for_role(): void
    {
        ['job' => $job] = $this->createJobWithAssignment('pickup');

        $address = $job->assignedAddressForRole('pickup');

        $this->assertInstanceOf(Address::class, $address);
        $this->assertEquals('Vienna', $address->city);
    }

    public function test_assigned_address_for_role_returns_null_when_missing(): void
    {
        ['job' => $job] = $this->createJobWithAssignment('pickup');

        $this->assertNull($job->assignedAddressForRole('delivery'));
    }

    public function test_assigned_addresses(): void
    {
        $user = User::factory()->create();
        $link1 = $user->addAddress(['city' => 'Vienna'], AddressLinkType::Office);
        $link2 = $user->addAddress(['city' => 'Graz'], AddressLinkType::Home);

        $job = Job::create(['title' => 'Move']);
        $job->assignAddressLink($link1, 'pickup');
        $job->assignAddressLink($link2, 'delivery');

        $addresses = $job->assignedAddresses();

        $this->assertCount(2, $addresses);
        $this->assertContains('Vienna', $addresses->pluck('city')->all());
        $this->assertContains('Graz', $addresses->pluck('city')->all());
    }

    public function test_has_address_assignments(): void
    {
        $job = Job::create(['title' => 'Empty Job']);
        $this->assertFalse($job->hasAddressAssignments());

        ['job' => $jobWithAssignment] = $this->createJobWithAssignment();
        $this->assertTrue($jobWithAssignment->hasAddressAssignments());
    }

    public function test_has_assignment_for_role(): void
    {
        ['job' => $job] = $this->createJobWithAssignment('pickup');

        $this->assertTrue($job->hasAssignmentForRole('pickup'));
        $this->assertFalse($job->hasAssignmentForRole('delivery'));
    }

    // ─── scope: forRole ──────────────────────────────────────────

    public function test_for_role_scope_on_assignment(): void
    {
        $user = User::factory()->create();
        $link = $user->addAddress(['city' => 'Vienna'], AddressLinkType::Office);

        $job = Job::create(['title' => 'Move']);
        $job->assignAddressLink($link, 'pickup');
        $job->assignAddressLink($link, 'delivery');

        $pickups = AddressAssignment::forRole('pickup')->get();
        $this->assertCount(1, $pickups);
        $this->assertEquals('pickup', $pickups->first()->role);
    }

    // ─── cross-model assignment ──────────────────────────────────

    public function test_same_address_link_assigned_to_different_models(): void
    {
        $user = User::factory()->create();
        $link = $user->addAddress([
            'street' => 'Ringstraße 10',
            'city' => 'Vienna',
            'country_code' => 'AT',
        ], AddressLinkType::Office);

        $job1 = Job::create(['title' => 'Job Alpha']);
        $job2 = Job::create(['title' => 'Job Beta']);

        $a1 = $job1->assignAddressLink($link, 'pickup');
        $a2 = $job2->assignAddressLink($link, 'delivery');

        // Both assignments point to the same address link
        $this->assertEquals($link->id, $a1->address_link_id);
        $this->assertEquals($link->id, $a2->address_link_id);

        // Each job has its own assignment
        $this->assertCount(1, $job1->fresh()->addressAssignments);
        $this->assertCount(1, $job2->fresh()->addressAssignments);

        // The address link knows about both
        $this->assertCount(2, $link->fresh()->assignments);
    }

    // ═══════════════════════════════════════════════════════════════
    // EXHAUSTIVE TESTS — every developer interaction surface
    // ═══════════════════════════════════════════════════════════════

    // ─── Address model — edge cases ──────────────────────────────

    public function test_create_completely_empty_address(): void
    {
        $address = Address::create([]);

        $this->assertNotNull($address->id);
        $this->assertNull($address->street);
        $this->assertNull($address->city);
        $this->assertNull($address->country_code);
        $this->assertFalse($address->hasCoordinates());
        $this->assertFalse($address->hasAltitude());
    }

    public function test_update_address_fields(): void
    {
        $address = Address::create(['city' => 'Vienna', 'country_code' => 'AT']);

        $address->update(['city' => 'Linz', 'street' => 'Hauptplatz 1']);

        $fresh = $address->fresh();
        $this->assertEquals('Linz', $fresh->city);
        $this->assertEquals('Hauptplatz 1', $fresh->street);
        $this->assertEquals('AT', $fresh->country_code);
    }

    public function test_address_formatted_with_building_floor_room(): void
    {
        $address = Address::create([
            'building' => 'Tower A',
            'floor' => 'B2',
            'room' => '42',
        ]);

        $formatted = $address->formatted;

        $this->assertStringContainsString('(Tower A)', $formatted);
        $this->assertStringContainsString('Floor B2', $formatted);
        $this->assertStringContainsString('Room 42', $formatted);
    }

    public function test_address_formatted_when_all_empty(): void
    {
        $address = Address::create([]);

        $this->assertEquals('', $address->formatted);
    }

    public function test_address_partial_coordinates_lat_only(): void
    {
        $address = Address::create(['latitude' => 48.2082]);

        $this->assertFalse($address->hasCoordinates());
    }

    public function test_address_partial_coordinates_lng_only(): void
    {
        $address = Address::create(['longitude' => 16.3738]);

        $this->assertFalse($address->hasCoordinates());
    }

    public function test_address_to_coordinates_without_any(): void
    {
        $address = Address::create(['city' => 'NoCoords']);

        $coords = $address->toCoordinates();

        $this->assertNull($coords['latitude']);
        $this->assertNull($coords['longitude']);
        $this->assertNull($coords['altitude']);
    }

    public function test_address_links_relationship(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Corp']);

        $address = Address::create(['city' => 'Vienna']);

        $user->linkAddress($address, AddressLinkType::Home);
        $company->linkAddress($address, AddressLinkType::Headquarters);

        $links = $address->links;

        $this->assertCount(2, $links);
    }

    public function test_restore_soft_deleted_address(): void
    {
        $address = Address::create(['city' => 'Restored']);
        $id = $address->id;

        $address->delete();
        $this->assertNull(Address::find($id));

        Address::withTrashed()->find($id)->restore();
        $this->assertNotNull(Address::find($id));
        $this->assertEquals('Restored', Address::find($id)->city);
    }

    public function test_address_fillable_notes(): void
    {
        $address = Address::create([
            'notes' => 'Ring doorbell twice. Dog in yard.',
        ]);

        $this->assertEquals('Ring doorbell twice. Dog in yard.', $address->notes);
    }

    public function test_address_meta_via_has_meta(): void
    {
        $address = Address::create([
            'city' => 'MetaTest',
            'meta' => ['what3words' => 'filled.count.soap'],
        ]);

        $meta = $address->getMeta();
        $this->assertEquals('filled.count.soap', $meta->what3words);
    }

    public function test_address_latitude_longitude_cast_to_float(): void
    {
        $address = Address::create([
            'latitude' => '48.2082',
            'longitude' => '16.3738',
            'altitude' => '171.5',
        ]);

        $this->assertIsFloat($address->latitude);
        $this->assertIsFloat($address->longitude);
        $this->assertIsFloat($address->altitude);
    }

    // ─── AddressLink — edge cases ────────────────────────────────

    public function test_update_link_type(): void
    {
        $user = User::factory()->create();
        $link = $user->addAddress(['city' => 'X'], AddressLinkType::Home);

        $link->update(['type' => AddressLinkType::Office->value]);

        $this->assertEquals(AddressLinkType::Office, $link->fresh()->type);
    }

    public function test_update_link_label(): void
    {
        $user = User::factory()->create();
        $link = $user->addAddress(['city' => 'X'], AddressLinkType::Other, ['label' => 'Old']);

        $link->update(['label' => 'New Label']);

        $this->assertEquals('New Label', $link->fresh()->label);
    }

    public function test_is_active_with_future_active_from(): void
    {
        $user = User::factory()->create();
        $link = $user->addAddress(['city' => 'Future'], AddressLinkType::Home, [
            'active_from' => now()->addMonth(),
        ]);

        $this->assertFalse($link->isActive());
    }

    public function test_is_active_with_past_active_from_only(): void
    {
        $user = User::factory()->create();
        $link = $user->addAddress(['city' => 'Past'], AddressLinkType::Home, [
            'active_from' => now()->subMonth(),
        ]);

        $this->assertTrue($link->isActive());
    }

    public function test_is_active_with_future_active_until_only(): void
    {
        $user = User::factory()->create();
        $link = $user->addAddress(['city' => 'StillGood'], AddressLinkType::Home, [
            'active_until' => now()->addYear(),
        ]);

        $this->assertTrue($link->isActive());
    }

    public function test_is_active_with_neither_temporal_bound(): void
    {
        $user = User::factory()->create();
        $link = $user->addAddress(['city' => 'Forever'], AddressLinkType::Home);

        $this->assertTrue($link->isActive());
    }

    public function test_scope_chaining_active_and_of_type(): void
    {
        $user = User::factory()->create();

        // Active home
        $user->addAddress(['city' => 'ActiveHome'], AddressLinkType::Home, [
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);
        // Expired home
        $user->addAddress(['city' => 'ExpiredHome'], AddressLinkType::Home, [
            'active_until' => now()->subDay(),
        ]);
        // Active office
        $user->addAddress(['city' => 'ActiveOffice'], AddressLinkType::Office);

        $activeHomes = $user->addressLinks()->active()->ofType(AddressLinkType::Home)->get();

        $this->assertCount(1, $activeHomes);
        $this->assertEquals('ActiveHome', $activeHomes->first()->address->city);
    }

    public function test_scope_primary_and_of_type(): void
    {
        $user = User::factory()->create();

        $user->addAddress(['city' => 'A'], AddressLinkType::Home, ['is_primary' => true]);
        $user->addAddress(['city' => 'B'], AddressLinkType::Home);
        $user->addAddress(['city' => 'C'], AddressLinkType::Office, ['is_primary' => true]);

        $primaryHomes = $user->addressLinks()->primary()->ofType(AddressLinkType::Home)->get();

        $this->assertCount(1, $primaryHomes);
    }

    public function test_scope_of_type_with_string(): void
    {
        $user = User::factory()->create();
        $user->addAddress(['city' => 'A'], AddressLinkType::Shipping);

        $result = $user->addressLinks()->ofType('shipping')->get();
        $this->assertCount(1, $result);
    }

    public function test_addressable_for_company(): void
    {
        $company = Company::create(['name' => 'Widget Inc']);
        $link = $company->addAddress(['city' => 'Zurich'], AddressLinkType::Headquarters);

        $fresh = AddressLink::find($link->id);
        $this->assertInstanceOf(Company::class, $fresh->addressable);
        $this->assertEquals('Widget Inc', $fresh->addressable->name);
    }

    // ─── HasAddresses trait — more interactions ──────────────────

    public function test_same_address_reused_across_three_users(): void
    {
        $address = Address::create([
            'street' => 'Shared Office 1',
            'city' => 'Vienna',
            'country_code' => 'AT',
        ]);

        $u1 = User::factory()->create();
        $u2 = User::factory()->create();
        $u3 = User::factory()->create();

        $u1->linkAddress($address, AddressLinkType::Office);
        $u2->linkAddress($address, AddressLinkType::Office);
        $u3->linkAddress($address, AddressLinkType::Office);

        $this->assertCount(3, $address->links);
        $this->assertTrue($u1->hasAddresses());
        $this->assertTrue($u2->hasAddresses());
        $this->assertTrue($u3->hasAddresses());

        // All three see the same physical address
        $this->assertEquals($address->id, $u1->addresses->first()->id);
        $this->assertEquals($address->id, $u2->addresses->first()->id);
        $this->assertEquals($address->id, $u3->addresses->first()->id);
    }

    public function test_multiple_addresses_of_same_type(): void
    {
        $user = User::factory()->create();

        $user->addAddress(['city' => 'Office A'], AddressLinkType::Office);
        $user->addAddress(['city' => 'Office B'], AddressLinkType::Office);
        $user->addAddress(['city' => 'Office C'], AddressLinkType::Office);

        $offices = $user->addressesOfType(AddressLinkType::Office);
        $this->assertCount(3, $offices);
    }

    public function test_primary_address_without_type_filter(): void
    {
        $user = User::factory()->create();
        $user->addAddress(['city' => 'NotPrimary'], AddressLinkType::Home);
        $user->addAddress(['city' => 'IsPrimary'], AddressLinkType::Office, ['is_primary' => true]);

        $primary = $user->primaryAddress();

        $this->assertNotNull($primary);
        $this->assertEquals('IsPrimary', $primary->city);
    }

    public function test_primary_address_null_when_no_primary_at_all(): void
    {
        $user = User::factory()->create();
        $user->addAddress(['city' => 'A'], AddressLinkType::Home);
        $user->addAddress(['city' => 'B'], AddressLinkType::Office);

        $this->assertNull($user->primaryAddress());
    }

    public function test_remove_address_link_returns_false_for_nonexistent(): void
    {
        $user = User::factory()->create();

        // removeAddressLink deletes by query; 0 rows affected → bool(false)
        $this->assertFalse($user->removeAddressLink(99999));
    }

    public function test_detach_address_by_id(): void
    {
        $user = User::factory()->create();
        $address = Address::create(['city' => 'ById']);
        $user->linkAddress($address, AddressLinkType::Home);
        $user->linkAddress($address, AddressLinkType::Billing);

        $removed = $user->detachAddress($address->id);

        $this->assertEquals(2, $removed);
        $this->assertFalse($user->hasAddresses());
    }

    public function test_link_address_with_all_pivot_fields(): void
    {
        $user = User::factory()->create();
        $address = Address::create(['city' => 'Full Pivot']);

        $link = $user->linkAddress($address, AddressLinkType::Temporary, [
            'label' => 'Summer Rental',
            'is_primary' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addMonths(3),
            'meta' => ['lease_id' => 'L-2026-001'],
        ]);

        $this->assertEquals('Summer Rental', $link->label);
        $this->assertTrue($link->is_primary);
        $this->assertNotNull($link->active_from);
        $this->assertNotNull($link->active_until);
        $this->assertTrue($link->isActive());
        $this->assertEquals('L-2026-001', $link->getMeta()->lease_id);
    }

    public function test_addresses_morphtomany_pivot_fields(): void
    {
        $user = User::factory()->create();
        $user->addAddress(
            ['city' => 'PivotTest'],
            AddressLinkType::Home,
            ['label' => 'My Flat', 'is_primary' => true]
        );

        $address = $user->addresses()->first();
        $pivot = $address->pivot;

        $this->assertEquals('home', $pivot->type);
        $this->assertEquals('My Flat', $pivot->label);
        $this->assertEquals(1, $pivot->is_primary);
        $this->assertNotNull($pivot->id);
        $this->assertNotNull($pivot->created_at);
    }

    public function test_same_address_billing_and_shipping(): void
    {
        $user = User::factory()->create();
        $address = Address::create([
            'street' => 'Main Street 1',
            'city' => 'Graz',
            'country_code' => 'AT',
        ]);

        $billingLink = $user->linkAddress($address, AddressLinkType::Billing, ['is_primary' => true]);
        $shippingLink = $user->linkAddress($address, AddressLinkType::Shipping, ['is_primary' => true]);

        // Both link types exist
        $this->assertTrue($user->hasAddressOfType(AddressLinkType::Billing));
        $this->assertTrue($user->hasAddressOfType(AddressLinkType::Shipping));

        // Both resolve to same address
        $billingAddr = $user->primaryAddress(AddressLinkType::Billing);
        $shippingAddr = $user->primaryAddress(AddressLinkType::Shipping);
        $this->assertEquals($billingAddr->id, $shippingAddr->id);
    }

    public function test_set_primary_clears_only_same_type(): void
    {
        $user = User::factory()->create();

        $home1 = $user->addAddress(['city' => 'Home1'], AddressLinkType::Home, ['is_primary' => true]);
        $home2 = $user->addAddress(['city' => 'Home2'], AddressLinkType::Home);
        $office1 = $user->addAddress(['city' => 'Office1'], AddressLinkType::Office, ['is_primary' => true]);

        $user->setPrimaryAddressLink($home2->id);

        $this->assertFalse($home1->fresh()->is_primary);
        $this->assertTrue($home2->fresh()->is_primary);
        $this->assertTrue($office1->fresh()->is_primary); // untouched
    }

    public function test_multiple_primary_addresses_across_types(): void
    {
        $user = User::factory()->create();

        $user->addAddress(['city' => 'HomeP'], AddressLinkType::Home, ['is_primary' => true]);
        $user->addAddress(['city' => 'OfficeP'], AddressLinkType::Office, ['is_primary' => true]);
        $user->addAddress(['city' => 'BillingP'], AddressLinkType::Billing, ['is_primary' => true]);

        $this->assertEquals('HomeP', $user->primaryAddress(AddressLinkType::Home)->city);
        $this->assertEquals('OfficeP', $user->primaryAddress(AddressLinkType::Office)->city);
        $this->assertEquals('BillingP', $user->primaryAddress(AddressLinkType::Billing)->city);
    }

    // ─── AddressLinkType enum — exhaustive ───────────────────────

    public function test_all_enum_cases_exist(): void
    {
        $cases = AddressLinkType::cases();
        $this->assertCount(17, $cases);

        $values = array_map(fn($c) => $c->value, $cases);
        $this->assertContains('home', $values);
        $this->assertContains('secondary_residence', $values);
        $this->assertContains('office', $values);
        $this->assertContains('headquarters', $values);
        $this->assertContains('branch', $values);
        $this->assertContains('factory', $values);
        $this->assertContains('warehouse', $values);
        $this->assertContains('shipping', $values);
        $this->assertContains('billing', $values);
        $this->assertContains('return', $values);
        $this->assertContains('pickup', $values);
        $this->assertContains('point_of_interest', $values);
        $this->assertContains('site', $values);
        $this->assertContains('temporary', $values);
        $this->assertContains('contact', $values);
        $this->assertContains('legal', $values);
        $this->assertContains('other', $values);
    }

    public function test_all_enum_values_are_unique(): void
    {
        $values = array_map(fn($c) => $c->value, AddressLinkType::cases());
        $this->assertEquals(count($values), count(array_unique($values)));
    }

    public function test_all_enum_labels_are_nonempty(): void
    {
        foreach (AddressLinkType::cases() as $case) {
            $this->assertNotEmpty($case->label(), "Label for {$case->value} is empty");
        }
    }

    public function test_enum_try_from_invalid_returns_null(): void
    {
        $this->assertNull(AddressLinkType::tryFrom('nonexistent'));
        $this->assertNull(AddressLinkType::tryFrom(''));
    }

    public function test_each_enum_type_can_be_used_as_link(): void
    {
        $user = User::factory()->create();

        foreach (AddressLinkType::cases() as $case) {
            $link = $user->addAddress(['city' => "City_{$case->value}"], $case);
            $this->assertEquals($case, $link->type, "Failed to link with type {$case->value}");
        }

        $this->assertCount(17, $user->addressLinks);
    }

    // ─── Address deletion cascades ───────────────────────────────

    public function test_force_delete_address_cascades_links_and_assignments(): void
    {
        $user = User::factory()->create();
        $link = $user->addAddress([
            'city' => 'CascadeAll',
        ], AddressLinkType::Office);

        $job = Job::create(['title' => 'CascadeJob']);
        $assignment = $job->assignAddressLink($link, 'pickup');

        $addressId = $link->address_id;

        // Force-delete the address (not soft-delete)
        Address::withTrashed()->find($addressId)->forceDelete();

        // Link gone
        $this->assertDatabaseMissing('address_links', ['id' => $link->id]);
        // Assignment gone
        $this->assertDatabaseMissing('address_assignments', ['id' => $assignment->id]);
    }

    public function test_soft_delete_address_preserves_links(): void
    {
        $user = User::factory()->create();
        $link = $user->addAddress(['city' => 'SoftDel'], AddressLinkType::Home);

        $link->address->delete(); // soft delete

        // Links remain because we only soft-deleted
        $this->assertDatabaseHas('address_links', ['id' => $link->id]);
    }

    // ─── AddressAssignment — additional interactions ─────────────

    public function test_update_assignment_role(): void
    {
        ['assignment' => $assignment] = $this->createJobWithAssignment('pickup');

        $assignment->update(['role' => 'origin']);

        $this->assertEquals('origin', $assignment->fresh()->role);
    }

    public function test_update_assignment_label(): void
    {
        ['assignment' => $assignment] = $this->createJobWithAssignment('pickup');

        $assignment->update(['label' => 'Updated Label']);

        $this->assertEquals('Updated Label', $assignment->fresh()->label);
    }

    public function test_assignment_meta_get_and_set(): void
    {
        $user = User::factory()->create();
        $link = $user->addAddress(['city' => 'Y']);

        $job = Job::create(['title' => 'Meta Job']);
        $assignment = $job->assignAddressLink($link, 'delivery', [
            'meta' => ['eta' => '14:00', 'requires_signature' => true],
        ]);

        $meta = $assignment->getMeta();
        $this->assertEquals('14:00', $meta->eta);
        $this->assertTrue($meta->requires_signature);
    }

    public function test_remove_address_assignment_returns_false_for_nonexistent(): void
    {
        $job = Job::create(['title' => 'Empty']);

        $this->assertFalse($job->removeAddressAssignment(99999));
    }

    public function test_duplicate_assignment_same_link_same_role(): void
    {
        $user = User::factory()->create();
        $link = $user->addAddress(['city' => 'Dup']);

        $job = Job::create(['title' => 'Dup Test']);
        $a1 = $job->assignAddressLink($link, 'stop');
        $a2 = $job->assignAddressLink($link, 'stop');

        // Both assignments are created (no unique constraint)
        $this->assertNotEquals($a1->id, $a2->id);
        $this->assertCount(2, $job->fresh()->addressAssignments);
    }

    public function test_replace_assignment_for_role(): void
    {
        $user = User::factory()->create();
        $link1 = $user->addAddress(['city' => 'Old'], AddressLinkType::Home);
        $link2 = $user->addAddress(['city' => 'New'], AddressLinkType::Office);

        $job = Job::create(['title' => 'Replace']);
        $job->assignAddressLink($link1, 'pickup');

        // Replace: remove old, add new
        $job->removeAssignmentsForRole('pickup');
        $job->assignAddressLink($link2, 'pickup');

        $address = $job->assignedAddressForRole('pickup');
        $this->assertEquals('New', $address->city);
        $this->assertCount(1, $job->addressAssignmentsForRole('pickup'));
    }

    public function test_assignment_address_through_returns_null_for_soft_deleted_address(): void
    {
        ['assignment' => $assignment, 'link' => $link] = $this->createJobWithAssignment();

        $link->address->delete(); // soft delete

        // HasOneThrough does not traverse soft-deleted by default
        $fresh = AddressAssignment::with('address')->find($assignment->id);
        $this->assertNull($fresh->address);
    }

    public function test_assignment_address_link_has_full_access_to_address(): void
    {
        $user = User::factory()->create();
        $link = $user->addAddress([
            'street' => 'Long Road 42',
            'city' => 'Salzburg',
            'postal_code' => '5020',
            'country_code' => 'AT',
            'latitude' => 47.8095,
            'longitude' => 13.0550,
        ], AddressLinkType::Home);

        $job = Job::create(['title' => 'Full Access']);
        $assignment = $job->assignAddressLink($link, 'delivery');

        // Access through loaded relations
        $address = $assignment->addressLink->address;
        $this->assertEquals('Long Road 42', $address->street);
        $this->assertEquals('Salzburg', $address->city);
        $this->assertEquals('5020', $address->postal_code);
        $this->assertEquals('AT', $address->country_code);
        $this->assertTrue($address->hasCoordinates());
        $this->assertStringContainsString('Salzburg', $address->formatted);
    }

    // ─── AddressService — additional coverage ────────────────────

    public function test_merge_reassigns_assignments_through_repointed_links(): void
    {
        $user = User::factory()->create();
        $target = Address::create(['city' => 'Target']);
        $duplicate = Address::create(['city' => 'Dup']);

        $link = $user->linkAddress($duplicate, AddressLinkType::Office);
        $job = Job::create(['title' => 'MergeJob']);
        $assignment = $job->assignAddressLink($link, 'pickup');

        $this->service()->merge($target, $duplicate);

        // Link now points to target
        $freshLink = AddressLink::find($link->id);
        $this->assertEquals($target->id, $freshLink->address_id);

        // Assignment still works through the link
        $assignedAddr = $job->assignedAddressForRole('pickup');
        $this->assertEquals('Target', $assignedAddr->city);
    }

    public function test_nearby_with_miles(): void
    {
        Address::create(['city' => 'Close', 'latitude' => 48.21, 'longitude' => 16.38]);
        Address::create(['city' => 'Far', 'latitude' => 47.0707, 'longitude' => 15.4395]);

        // ~3 mi radius
        $results = $this->service()->nearby(48.2082, 16.3738, 3, 'mi');

        $this->assertCount(1, $results);
        $this->assertEquals('Close', $results->first()->city);
    }

    public function test_nearby_empty_results(): void
    {
        Address::create(['city' => 'Far', 'latitude' => -33.8688, 'longitude' => 151.2093]);

        // Search near Vienna, nothing within 1 km
        $results = $this->service()->nearby(48.2082, 16.3738, 1);

        $this->assertEmpty($results);
    }

    public function test_closest_among_many(): void
    {
        Address::create(['city' => 'A', 'latitude' => 48.30, 'longitude' => 16.50]);
        Address::create(['city' => 'B', 'latitude' => 48.25, 'longitude' => 16.42]);
        Address::create(['city' => 'C', 'latitude' => 48.21, 'longitude' => 16.38]);
        Address::create(['city' => 'D', 'latitude' => 47.07, 'longitude' => 15.44]);

        $closest = $this->service()->closest(48.2082, 16.3738);

        $this->assertEquals('C', $closest->city);
    }

    public function test_in_country_with_lowercase(): void
    {
        Address::create(['city' => 'Vienna', 'country_code' => 'AT']);

        // Service uppercases the input
        $result = $this->service()->inCountry('at')->get();

        $this->assertCount(1, $result);
    }

    public function test_find_duplicates_none(): void
    {
        $address = Address::create(['street' => 'Unique', 'city' => 'A']);
        Address::create(['street' => 'Different', 'city' => 'B']);

        $dups = $this->service()->findDuplicates($address);

        $this->assertCount(0, $dups);
    }

    public function test_find_duplicates_ignores_soft_deleted(): void
    {
        $addr = Address::create(['street' => 'Same', 'city' => 'X', 'country_code' => 'AT']);
        $dup = Address::create(['street' => 'Same', 'city' => 'X', 'country_code' => 'AT']);
        $dup->delete(); // soft delete

        $dups = $this->service()->findDuplicates($addr);

        $this->assertCount(0, $dups);
    }

    public function test_format_minimal(): void
    {
        $addr = Address::create(['city' => 'Lonely']);

        $this->assertEquals('Lonely', $this->service()->format($addr));
    }

    public function test_format_multiline_minimal(): void
    {
        $addr = Address::create(['city' => 'Solo']);

        $this->assertEquals('Solo', $this->service()->formatMultiline($addr));
    }

    public function test_format_multiline_with_county(): void
    {
        $addr = Address::create([
            'street' => 'Main St',
            'city' => 'Springfield',
            'state' => 'IL',
            'county' => 'Sangamon',
            'country_code' => 'US',
        ]);

        $multi = $this->service()->formatMultiline($addr);

        $this->assertStringContainsString('Sangamon', $multi);
        $this->assertStringContainsString('Springfield', $multi);
        $this->assertStringContainsString('US', $multi);
    }

    public function test_format_coordinates_with_altitude_negative(): void
    {
        $addr = Address::create([
            'latitude' => 31.5,
            'longitude' => 35.5,
            'altitude' => -430.5,
        ]);

        $result = $this->service()->formatCoordinates($addr);

        $this->assertStringContainsString('N', $result);
        $this->assertStringContainsString('E', $result);
        $this->assertStringContainsString('-430.50', $result);
        $this->assertStringContainsString('AMSL', $result);
    }

    public function test_dms_to_decimal_east(): void
    {
        // 16°22'25.68"E → 16.3738
        $result = $this->service()->dmsToDecimal(16, 22, 25.68, 'E');
        $this->assertEqualsWithDelta(16.3738, $result, 0.001);
    }

    public function test_dms_to_decimal_west(): void
    {
        $result = $this->service()->dmsToDecimal(73, 59, 8.4, 'W');
        $this->assertLessThan(0, $result);
    }

    public function test_decimal_to_dms_south(): void
    {
        $result = $this->service()->decimalToDms(-33.8688, 'lat');
        $this->assertEquals('S', $result['direction']);
        $this->assertEquals(33, $result['degrees']);
    }

    public function test_decimal_to_dms_east(): void
    {
        $result = $this->service()->decimalToDms(16.3738, 'lng');
        $this->assertEquals('E', $result['direction']);
        $this->assertEquals(16, $result['degrees']);
    }

    public function test_dms_roundtrip_longitude(): void
    {
        $original = -73.9856;
        $dms = $this->service()->decimalToDms($original, 'lng');
        $back = $this->service()->dmsToDecimal($dms['degrees'], $dms['minutes'], $dms['seconds'], $dms['direction']);

        $this->assertEqualsWithDelta($original, $back, 0.0001);
    }

    // ═══════════════════════════════════════════════════════════════
    // INTEGRATION — complex multi-model scenarios
    // ═══════════════════════════════════════════════════════════════

    public function test_full_lifecycle_create_link_assign_reassign_remove(): void
    {
        // 1. Create user + address
        $user = User::factory()->create();
        $link = $user->addAddress([
            'street' => 'Lifecycle Str. 1',
            'city' => 'Vienna',
            'country_code' => 'AT',
        ], AddressLinkType::Office, ['is_primary' => true]);

        $this->assertTrue($user->hasAddresses());

        // 2. Create job, assign the link
        $job = Job::create(['title' => 'Lifecycle Job']);
        $assignment = $job->assignAddressLink($link, 'pickup');

        $this->assertTrue($job->hasAddressAssignments());
        $this->assertEquals('Vienna', $job->assignedAddressForRole('pickup')->city);

        // 3. User moves: create new address, reassign job
        $newLink = $user->addAddress([
            'street' => 'New Place 5',
            'city' => 'Graz',
            'country_code' => 'AT',
        ], AddressLinkType::Office);

        // Promote new link as primary (unsets old primary for same type)
        $user->setPrimaryAddressLink($newLink->id);

        $job->removeAssignmentsForRole('pickup');
        $job->assignAddressLink($newLink, 'pickup');

        $this->assertEquals('Graz', $job->assignedAddressForRole('pickup')->city);

        // 4. Old link still exists but no longer primary
        $this->assertFalse($link->fresh()->is_primary);
        $this->assertTrue($newLink->fresh()->is_primary);

        // 5. Remove job assignments completely
        $job->removeAllAddressAssignments();
        $this->assertFalse($job->hasAddressAssignments());

        // 6. User still has 2 addresses
        $this->assertCount(2, $user->fresh()->addressLinks);
    }

    public function test_one_address_shared_user_company_job(): void
    {
        // One physical address used by 3 different models at different layers
        $address = Address::create([
            'street' => 'Shared Tower',
            'city' => 'Vienna',
            'country_code' => 'AT',
        ]);

        $user = User::factory()->create();
        $company = Company::create(['name' => 'SharedCorp']);

        // User & Company own links to the same address
        $userLink = $user->linkAddress($address, AddressLinkType::Office, ['label' => 'My Office']);
        $companyLink = $company->linkAddress($address, AddressLinkType::Headquarters);

        // Job is assigned both links for different roles
        $job = Job::create(['title' => 'Shared Job']);
        $job->assignAddressLink($userLink, 'pickup');
        $job->assignAddressLink($companyLink, 'billing');

        // Verify all three models reference the same address
        $this->assertEquals($address->id, $user->addresses->first()->id);
        $this->assertEquals($address->id, $company->addresses->first()->id);
        $this->assertEquals($address->id, $job->assignedAddressForRole('pickup')->id);
        $this->assertEquals($address->id, $job->assignedAddressForRole('billing')->id);

        // Address has 2 links
        $this->assertCount(2, $address->links);

        // But job has 2 assignments
        $this->assertCount(2, $job->addressAssignments);
    }

    public function test_labels_same_address_same_model_different_labels(): void
    {
        $user = User::factory()->create();
        $address = Address::create([
            'street' => 'Multi-Label Avenue',
            'city' => 'Munich',
        ]);

        $user->linkAddress($address, AddressLinkType::Other, ['label' => 'Start of project']);
        $user->linkAddress($address, AddressLinkType::Other, ['label' => 'End of project']);
        $user->linkAddress($address, AddressLinkType::Other, ['label' => 'Meeting point']);

        $links = $user->addressLinks()->get();
        $labels = $links->pluck('label')->sort()->values()->toArray();

        $this->assertCount(3, $links);
        $this->assertEquals(['End of project', 'Meeting point', 'Start of project'], $labels);
    }

    public function test_relabel_a_link(): void
    {
        $user = User::factory()->create();
        $link = $user->addAddress(['city' => 'X'], AddressLinkType::Other, ['label' => 'Temp Name']);

        $link->update(['label' => 'Permanent Name']);

        $this->assertEquals('Permanent Name', $link->fresh()->label);
    }

    public function test_counting_addresses_links_assignments(): void
    {
        $user = User::factory()->create();
        $link1 = $user->addAddress(['city' => 'A'], AddressLinkType::Home);
        $link2 = $user->addAddress(['city' => 'B'], AddressLinkType::Office);
        $link3 = $user->addAddress(['city' => 'C'], AddressLinkType::Billing);

        $job1 = Job::create(['title' => 'J1']);
        $job2 = Job::create(['title' => 'J2']);

        $job1->assignAddressLink($link1, 'pickup');
        $job1->assignAddressLink($link2, 'delivery');
        $job2->assignAddressLink($link1, 'pickup');

        $this->assertEquals(3, Address::count());
        $this->assertEquals(3, AddressLink::count());
        $this->assertEquals(3, AddressAssignment::count());
        $this->assertCount(3, $user->addressLinks);
        $this->assertCount(2, $job1->addressAssignments);
        $this->assertCount(1, $job2->addressAssignments);
    }

    public function test_temporal_link_not_started_yet(): void
    {
        $user = User::factory()->create();
        $link = $user->addAddress(['city' => 'Future'], AddressLinkType::Office, [
            'active_from' => now()->addWeek(),
        ]);

        $this->assertFalse($link->isActive());

        $activeLinks = $user->activeAddressLinks();
        $this->assertCount(0, $activeLinks);
    }

    public function test_temporal_link_started_today(): void
    {
        $user = User::factory()->create();
        $link = $user->addAddress(['city' => 'Today'], AddressLinkType::Office, [
            'active_from' => now(),
        ]);

        $this->assertTrue($link->isActive());
    }

    public function test_address_used_for_distance_after_linking(): void
    {
        $user = User::factory()->create();
        $link1 = $user->addAddress([
            'city' => 'Vienna',
            'latitude' => 48.2082,
            'longitude' => 16.3738,
        ], AddressLinkType::Home);

        $link2 = $user->addAddress([
            'city' => 'Graz',
            'latitude' => 47.0707,
            'longitude' => 15.4395,
        ], AddressLinkType::Office);

        $dist = $this->service()->distanceBetween($link1->address, $link2->address);

        $this->assertNotNull($dist);
        $this->assertEqualsWithDelta(145.0, $dist, 5.0);
    }

    public function test_nearby_through_assigned_address(): void
    {
        $user = User::factory()->create();
        Address::create(['city' => 'Near', 'latitude' => 48.21, 'longitude' => 16.38]);
        $link = $user->addAddress([
            'city' => 'Vienna',
            'latitude' => 48.2082,
            'longitude' => 16.3738,
        ], AddressLinkType::Office);

        $job = Job::create(['title' => 'Nearby Job']);
        $job->assignAddressLink($link, 'origin');

        // Get the address through the assignment, then find nearby
        $origin = $job->assignedAddressForRole('origin');
        $nearby = $this->service()->nearbyAddress($origin, 10);

        $this->assertNotEmpty($nearby);
        $this->assertNotContains($origin->id, $nearby->pluck('id')->all());
    }

    public function test_merge_with_assignments_full_scenario(): void
    {
        $user = User::factory()->create();

        // Two duplicate addresses
        $addr1 = Address::create(['street' => 'Same St', 'city' => 'Linz', 'country_code' => 'AT']);
        $addr2 = Address::create(['street' => 'Same St', 'city' => 'Linz', 'country_code' => 'AT']);

        $link1 = $user->linkAddress($addr1, AddressLinkType::Home);
        $link2 = $user->linkAddress($addr2, AddressLinkType::Office);

        $job = Job::create(['title' => 'Merge Job']);
        $job->assignAddressLink($link2, 'pickup');

        // Merge addr2 into addr1
        $reassigned = $this->service()->merge($addr1, $addr2);

        // link2 now points to addr1
        $this->assertEquals($addr1->id, AddressLink::find($link2->id)->address_id);

        // addr2 is soft-deleted
        $this->assertSoftDeleted('addresses', ['id' => $addr2->id]);

        // Assignment still works, now resolving to addr1
        $address = $job->assignedAddressForRole('pickup');
        $this->assertEquals($addr1->id, $address->id);
    }

    public function test_company_with_multiple_branch_addresses(): void
    {
        $company = Company::create(['name' => 'Multi-Branch Corp']);

        $company->addAddress(['city' => 'Vienna'], AddressLinkType::Headquarters, [
            'label' => 'Main HQ',
            'is_primary' => true,
        ]);
        $company->addAddress(['city' => 'Graz'], AddressLinkType::Branch, ['label' => 'South Branch']);
        $company->addAddress(['city' => 'Linz'], AddressLinkType::Branch, ['label' => 'North Branch']);
        $company->addAddress(['city' => 'Salzburg'], AddressLinkType::Warehouse);

        $this->assertCount(4, $company->addresses);
        $this->assertCount(2, $company->addressesOfType(AddressLinkType::Branch));
        $this->assertEquals('Vienna', $company->primaryAddress(AddressLinkType::Headquarters)->city);
        $this->assertNull($company->primaryAddress(AddressLinkType::Branch));
    }

    public function test_user_and_company_share_address_with_different_types_and_labels(): void
    {
        $address = Address::create([
            'street' => 'Business Park 5',
            'city' => 'Vienna',
            'country_code' => 'AT',
        ]);

        $user = User::factory()->create();
        $company = Company::create(['name' => 'Co-Located Inc']);

        $userLink = $user->linkAddress($address, AddressLinkType::Office, ['label' => 'My desk at CO']);
        $companyLink = $company->linkAddress($address, AddressLinkType::Headquarters, ['label' => 'Official HQ']);

        // Same address, different contexts
        $this->assertEquals($address->id, $userLink->address->id);
        $this->assertEquals($address->id, $companyLink->address->id);
        $this->assertEquals('My desk at CO', $userLink->label);
        $this->assertEquals('Official HQ', $companyLink->label);
        $this->assertEquals(AddressLinkType::Office, $userLink->type);
        $this->assertEquals(AddressLinkType::Headquarters, $companyLink->type);
    }

    public function test_job_with_pickup_delivery_waypoints(): void
    {
        $user = User::factory()->create();

        $home = $user->addAddress([
            'street' => 'Home St 1',
            'city' => 'Vienna',
            'latitude' => 48.2082,
            'longitude' => 16.3738,
        ], AddressLinkType::Home);

        $office = $user->addAddress([
            'street' => 'Office Blvd 42',
            'city' => 'Graz',
            'latitude' => 47.0707,
            'longitude' => 15.4395,
        ], AddressLinkType::Office);

        $company = Company::create(['name' => 'Warehouse Co']);
        $warehouse = $company->addAddress([
            'street' => 'Storage Lane 7',
            'city' => 'Linz',
            'latitude' => 48.3069,
            'longitude' => 14.2858,
        ], AddressLinkType::Warehouse);

        $job = Job::create(['title' => 'Piano Transport']);
        $job->assignAddressLink($home, 'pickup', ['label' => 'Customer home']);
        $job->assignAddressLink($warehouse, 'waypoint', ['label' => 'Temporary storage']);
        $job->assignAddressLink($office, 'delivery', ['label' => 'Customer office']);

        // All 3 assignments exist
        $this->assertCount(3, $job->addressAssignments);

        // Verify each role resolves to correct city
        $this->assertEquals('Vienna', $job->assignedAddressForRole('pickup')->city);
        $this->assertEquals('Linz', $job->assignedAddressForRole('waypoint')->city);
        $this->assertEquals('Graz', $job->assignedAddressForRole('delivery')->city);

        // Calculate distances along the route
        $pickupAddr = $job->assignedAddressForRole('pickup');
        $waypointAddr = $job->assignedAddressForRole('waypoint');
        $deliveryAddr = $job->assignedAddressForRole('delivery');

        $leg1 = $this->service()->distanceBetween($pickupAddr, $waypointAddr);
        $leg2 = $this->service()->distanceBetween($waypointAddr, $deliveryAddr);
        $total = $leg1 + $leg2;

        $this->assertGreaterThan(0, $leg1);
        $this->assertGreaterThan(0, $leg2);
        $this->assertGreaterThan($leg1, $total);
    }

    public function test_addresses_survive_link_removal(): void
    {
        $user = User::factory()->create();
        $link = $user->addAddress(['city' => 'Survive'], AddressLinkType::Home);
        $addressId = $link->address_id;

        $user->removeAddressLink($link->id);

        // Address record still exists
        $this->assertNotNull(Address::find($addressId));
        $this->assertEquals('Survive', Address::find($addressId)->city);
    }

    public function test_assignment_survives_when_job_is_deleted(): void
    {
        ['job' => $job, 'assignment' => $assignment, 'link' => $link] = $this->createJobWithAssignment();
        $assignmentId = $assignment->id;
        $linkId = $link->id;

        $job->delete();

        // Assignment row still exists in DB (no cascade from assignable)
        $this->assertNotNull(AddressAssignment::find($assignmentId));
        // Link still exists
        $this->assertNotNull(AddressLink::find($linkId));
    }

    public function test_bulk_detach_then_reattach(): void
    {
        $user = User::factory()->create();
        $user->addAddress(['city' => 'A'], AddressLinkType::Home);
        $user->addAddress(['city' => 'B'], AddressLinkType::Office);
        $user->addAddress(['city' => 'C'], AddressLinkType::Billing);

        $this->assertCount(3, $user->fresh()->addresses);

        $user->detachAllAddresses();
        $this->assertCount(0, $user->fresh()->addresses);

        // Addresses still exist, can re-link
        $addresses = Address::where('city', 'A')->orWhere('city', 'B')->get();
        foreach ($addresses as $address) {
            $user->linkAddress($address, AddressLinkType::Office);
        }

        $this->assertCount(2, $user->fresh()->addresses);
    }

    public function test_address_with_all_worldwide_formats(): void
    {
        // Japanese address
        $jp = Address::create([
            'street' => '丸の内1-9-2',
            'building' => 'グラントウキョウサウスタワー',
            'floor' => '20',
            'room' => '2001',
            'postal_code' => '100-6920',
            'city' => '東京都千代田区',
            'country_code' => 'JP',
        ]);
        $this->assertStringContainsString('丸の内', $jp->formatted);
        $this->assertStringContainsString('JP', $jp->formatted);

        // German format
        $de = Address::create([
            'street' => 'Friedrichstraße 43-45',
            'postal_code' => '10117',
            'city' => 'Berlin',
            'state' => 'Berlin',
            'country_code' => 'DE',
        ]);
        $this->assertStringContainsString('Friedrichstraße', $de->formatted);

        // US format
        $us = Address::create([
            'street' => '1600 Pennsylvania Avenue NW',
            'city' => 'Washington',
            'state' => 'DC',
            'postal_code' => '20500',
            'country_code' => 'US',
        ]);
        $this->assertStringContainsString('1600 Pennsylvania', $us->formatted);

        // Rural GPS-only "address"
        $rural = Address::create([
            'latitude' => -23.5505,
            'longitude' => -46.6333,
            'notes' => 'Third rock on the left past the baobab tree',
        ]);
        $this->assertTrue($rural->hasCoordinates());
        $this->assertEquals('', $rural->formatted); // no postal fields

        // Coordinates-only verification
        $coords = $this->service()->formatCoordinates($rural);
        $this->assertStringContainsString('S', $coords);
        $this->assertStringContainsString('W', $coords);
    }

    public function test_address_floor_with_non_numeric_values(): void
    {
        $tests = ['GF', 'B2', 'Mezzanine', 'P3', 'Rooftop', '½'];

        foreach ($tests as $floor) {
            $addr = Address::create(['floor' => $floor]);
            $this->assertEquals($floor, $addr->floor);
            $this->assertStringContainsString("Floor {$floor}", $addr->formatted);
        }
    }

    public function test_extreme_coordinates(): void
    {
        // North pole
        $np = Address::create(['latitude' => 90.0, 'longitude' => 0.0]);
        $this->assertTrue($np->hasCoordinates());

        // South pole
        $sp = Address::create(['latitude' => -90.0, 'longitude' => 0.0]);
        $this->assertTrue($sp->hasCoordinates());

        // Antimeridian
        $am = Address::create(['latitude' => 0.0, 'longitude' => 180.0]);
        $this->assertTrue($am->hasCoordinates());
        $this->assertStringContainsString('E', $this->service()->formatCoordinates($am));

        // Negative antimeridian
        $amn = Address::create(['latitude' => 0.0, 'longitude' => -180.0]);
        $this->assertStringContainsString('W', $this->service()->formatCoordinates($amn));
    }

    public function test_distance_between_poles(): void
    {
        $north = Address::create(['latitude' => 90.0, 'longitude' => 0.0]);
        $south = Address::create(['latitude' => -90.0, 'longitude' => 0.0]);

        $dist = $this->service()->distanceBetween($north, $south);

        // Half circumference ≈ 20,015 km
        $this->assertEqualsWithDelta(20015.0, $dist, 100.0);
    }

    public function test_haversine_zero_distance(): void
    {
        $dist = $this->service()->haversine(0.0, 0.0, 0.0, 0.0);
        $this->assertEquals(0.0, $dist);
    }

    public function test_address_service_is_singleton(): void
    {
        $a = app(AddressService::class);
        $b = app(AddressService::class);
        $c = address();

        $this->assertSame($a, $b);
        $this->assertSame($a, $c);
    }

    public function test_bounding_box_miles(): void
    {
        $boxKm = $this->service()->boundingBox(48.2082, 16.3738, 10, 'km');
        $boxMi = $this->service()->boundingBox(48.2082, 16.3738, 10, 'mi');

        // 10 mi > 10 km, so mile box should be larger
        $this->assertGreaterThan(
            $boxKm['maxLat'] - $boxKm['minLat'],
            $boxMi['maxLat'] - $boxMi['minLat']
        );
    }

    public function test_in_city_without_country(): void
    {
        Address::create(['city' => 'Springfield', 'country_code' => 'US', 'state' => 'IL']);
        Address::create(['city' => 'Springfield', 'country_code' => 'US', 'state' => 'MO']);

        $result = $this->service()->inCity('Springfield')->get();

        $this->assertCount(2, $result);
    }

    public function test_find_duplicates_multiple(): void
    {
        $origial = Address::create(['street' => 'A', 'city' => 'B', 'postal_code' => '1', 'country_code' => 'AT']);
        Address::create(['street' => 'A', 'city' => 'B', 'postal_code' => '1', 'country_code' => 'AT']);
        Address::create(['street' => 'A', 'city' => 'B', 'postal_code' => '1', 'country_code' => 'AT']);

        $dups = $this->service()->findDuplicates($origial);

        $this->assertCount(2, $dups);
    }

    public function test_format_multiline_street_only(): void
    {
        $addr = Address::create(['street' => 'Just a street']);

        $this->assertEquals('Just a street', $this->service()->formatMultiline($addr));
    }

    public function test_format_multiline_postal_and_city(): void
    {
        $addr = Address::create(['postal_code' => '1010', 'city' => 'Vienna']);

        $this->assertEquals('1010 Vienna', $this->service()->formatMultiline($addr));
    }

    // ─── trait coexistence ────────────────────────────────────────

    public function test_model_can_be_link_owner_and_assignment_consumer(): void
    {
        // This tests the scenario where a model uses BOTH traits —
        // we'll approximate by manually creating cross-references

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        // User1 owns an address
        $link = $user1->addAddress([
            'city' => 'Vienna',
        ], AddressLinkType::Home);

        // User2 also owns an address
        $link2 = $user2->addAddress([
            'city' => 'Graz',
        ], AddressLinkType::Home);

        // A Job references both
        $job = Job::create(['title' => 'Cross']);
        $a1 = $job->assignAddressLink($link, 'pickup');
        $a2 = $job->assignAddressLink($link2, 'delivery');

        // Through the assignment we can traverse back to the owner
        $pickupOwner = AddressAssignment::find($a1->id)->addressLink->addressable;
        $deliveryOwner = AddressAssignment::find($a2->id)->addressLink->addressable;

        $this->assertInstanceOf(User::class, $pickupOwner);
        $this->assertInstanceOf(User::class, $deliveryOwner);
        $this->assertEquals($user1->id, $pickupOwner->id);
        $this->assertEquals($user2->id, $deliveryOwner->id);
    }

    public function test_traverse_full_chain_assignment_to_owner(): void
    {
        $user = User::factory()->create();
        $link = $user->addAddress([
            'street' => 'Full Chain 1',
            'city' => 'Salzburg',
            'country_code' => 'AT',
        ], AddressLinkType::Office, ['label' => 'Salzburg Office']);

        $job = Job::create(['title' => 'Chain Job']);
        $assignment = $job->assignAddressLink($link, 'pickup', ['label' => 'Pickup Point']);

        // Start from assignment, traverse the full chain
        $fresh = AddressAssignment::with(['addressLink.address', 'addressLink.addressable'])->find($assignment->id);

        // Assignment → AddressLink
        $this->assertEquals('Salzburg Office', $fresh->addressLink->label);
        $this->assertEquals(AddressLinkType::Office, $fresh->addressLink->type);

        // AddressLink → Address
        $this->assertEquals('Full Chain 1', $fresh->addressLink->address->street);
        $this->assertEquals('Salzburg', $fresh->addressLink->address->city);

        // AddressLink → Owner
        $this->assertInstanceOf(User::class, $fresh->addressLink->addressable);
        $this->assertEquals($user->id, $fresh->addressLink->addressable->id);

        // Assignment → Assignable (Job)
        $this->assertEquals($job->id, $fresh->assignable->id);
    }

    public function test_addresses_with_all_enum_types_queryable(): void
    {
        $user = User::factory()->create();

        foreach (AddressLinkType::cases() as $type) {
            $user->addAddress(['city' => "City_{$type->value}"], $type);
        }

        // Query each type individually
        foreach (AddressLinkType::cases() as $type) {
            $result = $user->addressesOfType($type);
            $this->assertCount(1, $result, "Expected 1 address for type {$type->value}");
        }
    }

    public function test_detach_specific_address_keeps_other_links(): void
    {
        $user = User::factory()->create();
        $addr1 = Address::create(['city' => 'Keep']);
        $addr2 = Address::create(['city' => 'Remove']);

        $user->linkAddress($addr1, AddressLinkType::Home);
        $user->linkAddress($addr2, AddressLinkType::Office);
        $user->linkAddress($addr2, AddressLinkType::Billing);

        $user->detachAddress($addr2);

        $remaining = $user->fresh()->addressLinks;
        $this->assertCount(1, $remaining);
        $this->assertEquals($addr1->id, $remaining->first()->address_id);
    }

    public function test_active_links_with_mixed_temporal_data(): void
    {
        $user = User::factory()->create();

        // Always active (no bounds)
        $user->addAddress(['city' => 'Always'], AddressLinkType::Home);

        // Currently active (started yesterday, ends tomorrow)
        $user->addAddress(['city' => 'Current'], AddressLinkType::Office, [
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        // Expired (ended yesterday)
        $user->addAddress(['city' => 'Expired'], AddressLinkType::Billing, [
            'active_until' => now()->subDay(),
        ]);

        // Not yet started (starts tomorrow)
        $user->addAddress(['city' => 'Future'], AddressLinkType::Temporary, [
            'active_from' => now()->addDay(),
        ]);

        $activeLinks = $user->activeAddressLinks();
        $cities = $activeLinks->pluck('address.city')->sort()->values()->toArray();

        $this->assertCount(2, $activeLinks);
        $this->assertEquals(['Always', 'Current'], $cities);
    }

    public function test_expired_scope_excludes_active_and_future(): void
    {
        $user = User::factory()->create();

        $user->addAddress(['city' => 'Active'], AddressLinkType::Home);
        $user->addAddress(['city' => 'Expired1'], AddressLinkType::Office, [
            'active_until' => now()->subDay(),
        ]);
        $user->addAddress(['city' => 'Expired2'], AddressLinkType::Billing, [
            'active_until' => now()->subHour(),
        ]);
        $user->addAddress(['city' => 'Future'], AddressLinkType::Temporary, [
            'active_from' => now()->addDay(),
            'active_until' => now()->addMonth(),
        ]);

        $expired = $user->addressLinks()->expired()->get();
        $this->assertCount(2, $expired);

        $cities = $expired->pluck('address.city')->sort()->values()->toArray();
        $this->assertContains('Expired1', $cities);
        $this->assertContains('Expired2', $cities);
    }

    public function test_format_multiline_full_address(): void
    {
        $addr = Address::create([
            'street' => '350 Fifth Avenue',
            'street_extra' => 'Suite 3200',
            'building' => 'Empire State Building',
            'floor' => '32',
            'room' => '3201',
            'postal_code' => '10118',
            'city' => 'New York',
            'state' => 'NY',
            'county' => 'New York County',
            'country_code' => 'US',
        ]);

        $lines = explode("\n", $this->service()->formatMultiline($addr));

        $this->assertCount(5, $lines);
        $this->assertEquals('350 Fifth Avenue, Suite 3200', $lines[0]);
        $this->assertEquals('Empire State Building, Floor 32, Room 3201', $lines[1]);
        $this->assertEquals('10118 New York, NY', $lines[2]);
        $this->assertEquals('New York County', $lines[3]);
        $this->assertEquals('US', $lines[4]);
    }

    public function test_with_coordinates_excludes_partial(): void
    {
        Address::create(['city' => 'Full', 'latitude' => 48.0, 'longitude' => 16.0]);
        Address::create(['city' => 'LatOnly', 'latitude' => 48.0]);
        Address::create(['city' => 'LngOnly', 'longitude' => 16.0]);
        Address::create(['city' => 'None']);

        $result = $this->service()->withCoordinates()->get();

        $this->assertCount(1, $result);
        $this->assertEquals('Full', $result->first()->city);
    }

    public function test_nearby_does_not_include_no_coords_addresses(): void
    {
        Address::create(['city' => 'NoCoords']);
        Address::create(['city' => 'HasCoords', 'latitude' => 48.21, 'longitude' => 16.38]);

        $results = $this->service()->nearby(48.2082, 16.3738, 50);

        $cities = $results->pluck('city')->toArray();
        $this->assertContains('HasCoords', $cities);
        $this->assertNotContains('NoCoords', $cities);
    }

    public function test_address_assignment_meta_empty_object(): void
    {
        $user = User::factory()->create();
        $link = $user->addAddress(['city' => 'Z']);
        $job = Job::create(['title' => 'NoMeta']);

        $assignment = $job->assignAddressLink($link, 'pickup');

        $this->assertNull($assignment->meta);
    }

    public function test_link_meta_complex_nested(): void
    {
        $user = User::factory()->create();
        $link = $user->addAddress(['city' => 'Complex'], AddressLinkType::Office, [
            'meta' => [
                'access' => [
                    'code' => '4567',
                    'hours' => ['from' => '08:00', 'to' => '18:00'],
                ],
                'contacts' => ['reception', 'security'],
            ],
        ]);

        $meta = $link->getMeta();
        $this->assertEquals('4567', $meta->access->code);
        $this->assertEquals('08:00', $meta->access->hours->from);
        $this->assertCount(2, (array) $meta->contacts);
    }
}
