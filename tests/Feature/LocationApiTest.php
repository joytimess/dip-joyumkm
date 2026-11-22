<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class LocationApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.geocoding' => [
            'url' => 'https://geocoder.test', 'allowed_hosts' => ['geocoder.test'],
            'user_agent' => 'JoyUMKM test (geo@example.com)', 'timeout' => 3, 'cache_store' => 'array',
        ]]);
        Http::preventStrayRequests();
    }

    public function test_search_validates_caches_and_sends_only_the_location_query(): void
    {
        $this->postJson('/api/locations/search', ['q' => 'Cipedak Raya'])->assertUnauthorized();
        $this->postJson('/api/locations/reverse', ['lat' => 0, 'lon' => 0])->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/locations/search', ['q' => 'a'])->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->postJson('/api/locations/reverse', ['lat' => -91, 'lon' => 181])->assertUnprocessable()
            ->assertJsonValidationErrors(['lat', 'lon']);
        Http::assertNothingSent();

        Http::fake(['geocoder.test/search*' => Http::response([
            ['lat' => '-6.3', 'lon' => '106.8', 'display_name' => 'Cipedak Raya, Jakarta Selatan'],
            ['lat' => '-91', 'lon' => '106.8', 'display_name' => 'Invalid'],
        ])]);
        foreach (['Cipedak   Raya', 'cipedak raya'] as $query) {
            $this->postJson('/api/locations/search', ['q' => $query, 'url' => 'https://other.test'])
                ->assertOk()->assertExactJson(['data' => [
                    ['latitude' => -6.3, 'longitude' => 106.8, 'label' => 'Cipedak Raya, Jakarta Selatan'],
                ]]);
        }
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://geocoder.test/search?')
            && $request['q'] === 'Cipedak Raya'
            && $request['countrycodes'] === 'id'
            && $request['limit'] === 5
            && $request->hasHeader('User-Agent', 'JoyUMKM test (geo@example.com)'));
    }

    #[TestWith([
        ['state' => 'Daerah Khusus Ibukota Jakarta', 'city' => 'Jakarta', 'municipality' => 'Jakarta Selatan',
            'city_district' => 'Jagakarsa', 'village' => 'Cipedak', 'postcode' => '12630'],
        ['province' => 'DKI Jakarta', 'city' => 'Jakarta Selatan', 'district' => 'Jagakarsa', 'subdistrict' => 'Cipedak', 'postal_code' => '12630'],
    ])]
    #[TestWith([
        ['state' => 'Sumatra Barat', 'county' => 'Kabupaten Agam', 'town' => 'Lubuk Basung', 'village' => 'Nagari Uji', 'postcode' => '2641'],
        ['province' => 'Sumatera Barat', 'city' => 'Kabupaten Agam', 'district' => null, 'subdistrict' => 'Nagari Uji', 'postal_code' => null],
    ])]
    #[TestWith([
        ['state' => 'Provinsi tidak dikenal', 'village' => 'Desa Uji'],
        ['province' => null, 'city' => null, 'district' => null, 'subdistrict' => 'Desa Uji', 'postal_code' => null],
    ])]
    public function test_reverse_maps_only_available_administration(array $source, array $expected): void
    {
        Sanctum::actingAs(User::factory()->create());
        Http::fake(['geocoder.test/reverse*' => Http::response([
            'display_name' => 'Jalan Uji, Indonesia', 'address' => $source + ['country_code' => 'id'],
        ])]);
        $this->postJson('/api/locations/reverse', ['lat' => '-6.3', 'lon' => '106.8'])
            ->assertOk()->assertExactJson(['data' => $expected + ['address' => 'Jalan Uji, Indonesia']]);
    }

    public function test_upstream_errors_have_safe_responses_and_are_not_cached(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Http::fake(['geocoder.test/search*' => Http::sequence()
            ->push(['error' => 'private upstream error'], 503)->push([])]);
        $this->postJson('/api/locations/search', ['q' => 'Lokasi uji'])->assertServiceUnavailable()
            ->assertDontSee('private upstream error');
        $this->postJson('/api/locations/search', ['q' => 'Lokasi uji'])->assertOk()->assertExactJson(['data' => []]);
        $this->postJson('/api/locations/search', ['q' => 'Lokasi uji'])->assertOk()->assertExactJson(['data' => []]);
        Http::assertSentCount(2);
    }

    public function test_reverse_timeout_returns_service_unavailable(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Http::fake(['geocoder.test/reverse*' => Http::failedConnection('internal timeout details')]);
        $this->postJson('/api/locations/reverse', ['lat' => 0, 'lon' => 0])
            ->assertServiceUnavailable()->assertDontSee('internal timeout details');
    }

    public function test_public_provider_shares_cooldown_between_accounts_and_lookup_types(): void
    {
        config([
            'services.geocoding.url' => 'https://nominatim.openstreetmap.org',
            'services.geocoding.allowed_hosts' => ['nominatim.openstreetmap.org'],
            'services.geocoding.cache_store' => 'database',
        ]);
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([])]);
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/locations/search', ['q' => 'Lokasi pertama'])->assertOk();
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/locations/reverse', ['lat' => 0, 'lon' => 0])->assertTooManyRequests();
        $this->postJson('/api/locations/search', ['q' => 'Lokasi pertama'])->assertOk();
        Http::assertSentCount(1);
    }

    public function test_provider_host_allowlist_and_user_throttle(): void
    {
        Sanctum::actingAs(User::factory()->create());
        config(['services.geocoding.url' => 'https://other.test']);
        for ($attempt = 0; $attempt < 15; $attempt++) {
            $this->postJson('/api/locations/search', ['q' => 'Lokasi uji'])->assertServiceUnavailable();
        }
        $this->postJson('/api/locations/reverse', ['lat' => 0, 'lon' => 0])->assertTooManyRequests();
        Http::assertNothingSent();
    }
}
