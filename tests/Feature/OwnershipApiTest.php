<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Umkm;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class OwnershipApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected $seed = true;

    public function test_owner_can_read_detail_but_another_account_receives_the_same_404_as_missing_id(): void
    {
        $umkm = Umkm::factory()->create();
        $owner = $umkm->user;
        $other = User::factory()->create();
        $ownerToken = $owner->createToken('test')->plainTextToken;
        $otherToken = $other->createToken('test')->plainTextToken;

        $this->withToken($ownerToken)->getJson('/api/umkms/'.$umkm->id)
            ->assertOk()->assertJsonPath('data.id', $umkm->id)->assertJsonPath('data.latest_score', null);

        $this->app['auth']->forgetGuards();
        $foreign = $this->withToken($otherToken)->getJson('/api/umkms/'.$umkm->id.'?_umkm_id=999999');
        $missing = $this->withToken($otherToken)->getJson('/api/umkms/999999');

        $foreign->assertNotFound()->assertExactJson(['message' => 'Data tidak ditemukan.']);
        $missing->assertNotFound();
        $this->assertSame($foreign->json(), $missing->json());
    }
}
