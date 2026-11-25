<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Umkm;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PreventApiResponseCachingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_authenticated_api_responses_are_excluded_from_shared_caches(): void
    {
        $umkm = Umkm::factory()->create();
        $this->actingAs($umkm->user, 'sanctum');

        $response = $this->getJson('/api/umkms')->assertOk();

        $this->assertSame('no-cache', $response->headers->get('X-LiteSpeed-Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    public function test_unauthenticated_api_error_responses_are_also_excluded_from_shared_caches(): void
    {
        $response = $this->getJson('/api/umkms')->assertUnauthorized();

        $this->assertSame('no-cache', $response->headers->get('X-LiteSpeed-Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_static_page_shell_keeps_default_caching_behaviour(): void
    {
        $response = $this->get('/login')->assertOk();

        $this->assertNull($response->headers->get('X-LiteSpeed-Cache-Control'));
        $this->assertStringNotContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
}
