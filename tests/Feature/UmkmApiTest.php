<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Question;
use App\Models\Sector;
use App\Models\Umkm;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class UmkmApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected $seed = true;

    public function test_crud_persists_safe_fields_and_cascades_evaluation_answers(): void
    {
        $owner = $this->authenticate();
        $payload = $this->payload(['name' => '  Toko Baru  ', 'omzet' => 15000000]);

        $created = $this->postJson('/api/umkms', $payload)
            ->assertCreated()->assertJsonPath('data.name', 'Toko Baru')
            ->assertJsonPath('data.omzet', 15000000)
            ->assertJsonPath('data.evaluation_status', 'none')
            ->assertJsonPath('data.latest_score', null);
        $id = $created->json('data.id');
        $umkm = $owner->umkms()->findOrFail($id);
        $evaluation = $umkm->evaluations()->create(['status' => 'draft']);
        $answer = $evaluation->answers()->create(['question_id' => Question::query()->firstOrFail()->id, 'value' => 1]);

        $this->patchJson('/api/umkms/'.$id, ['omzet' => 9007199254740991, 'record_id' => 99999])
            ->assertOk()->assertJsonPath('data.omzet', 9007199254740991)
            ->assertJsonPath('data.name', 'Toko Baru');
        $this->assertDatabaseHas('umkms', ['id' => $id, 'user_id' => $owner->id, 'omzet' => 9007199254740991]);

        $this->putJson('/api/umkms/'.$id, $this->payload(['name' => 'Toko Diubah', 'province' => 'Bali']))
            ->assertOk()->assertJsonPath('data.name', 'Toko Diubah')->assertJsonPath('data.province', 'Bali');
        $this->deleteJson('/api/umkms/'.$id)->assertOk()
            ->assertJsonPath('data.message', 'UMKM dan seluruh evaluasinya berhasil dihapus.');
        $this->assertModelMissing($umkm);
        $this->assertModelMissing($evaluation);
        $this->assertModelMissing($answer);
    }

    public function test_names_are_trimmed_case_insensitive_unique_per_account_but_shared_between_accounts(): void
    {
        $owner = $this->authenticate();
        $this->postJson('/api/umkms', $this->payload(['name' => 'Warjon']))->assertCreated();
        $this->postJson('/api/umkms', $this->payload(['name' => ' warJON ']))
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->authenticate();
        $this->postJson('/api/umkms', $this->payload(['name' => 'Warjon']))->assertCreated();

        $this->assertDatabaseCount('umkms', 2);
        $this->assertSame(1, $owner->umkms()->count());
    }

    public function test_update_can_keep_own_name_but_cannot_take_another_record_name(): void
    {
        $owner = $this->authenticate();
        $first = Umkm::factory()->for($owner)->create(['name' => 'Pertama']);
        $second = Umkm::factory()->for($owner)->create(['name' => 'Kedua']);

        $this->patchJson('/api/umkms/'.$first->id, ['name' => 'PERTAMA'])
            ->assertOk()->assertJsonPath('data.name', 'PERTAMA');
        $this->patchJson('/api/umkms/'.$first->id, ['name' => ' kedua '])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertSame('PERTAMA', $first->fresh()->name);
        $this->assertSame('Kedua', $second->fresh()->name);
    }

    #[TestWith(['%'])]
    #[TestWith(['_'])]
    #[TestWith(['!'])]
    #[TestWith(['\\'])]
    public function test_search_treats_wildcards_and_escape_characters_as_literal_substrings(string $literal): void
    {
        $owner = $this->authenticate();
        $matching = Umkm::factory()->for($owner)->create(['name' => 'Toko '.$literal.' Bagus']);
        Umkm::factory()->for($owner)->create(['name' => 'Toko Tanpa Simbol']);
        Umkm::factory()->create(['name' => 'Asing '.$literal]);

        $this->getJson('/api/umkms?'.http_build_query(['search' => ' '.$literal.' ']))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $matching->id);
    }

    public function test_filters_are_combined_and_a_new_empty_draft_replaces_a_final_for_filtering(): void
    {
        $owner = $this->authenticate();
        $sector = Sector::query()->where('slug', 'jasa')->firstOrFail();
        $matching = Umkm::factory()->for($owner)->create(['name' => 'Toko Cocok', 'province' => 'Bali', 'sector_id' => $sector->id]);
        $matching->evaluations()->create(['status' => 'final', 'average_score' => 90, 'category' => 'SANGAT BAIK']);
        $matching->evaluations()->create(['status' => 'draft']);
        $otherProvince = Umkm::factory()->for($owner)->create(['name' => 'Toko Lain', 'province' => 'DKI Jakarta', 'sector_id' => $sector->id]);
        $otherProvince->evaluations()->create(['status' => 'draft']);
        Umkm::factory()->for($owner)->create(['name' => 'Toko Belum', 'province' => 'Bali', 'sector_id' => $sector->id]);

        $this->getJson('/api/umkms?'.http_build_query([
            'search' => 'Toko', 'province' => 'Bali', 'sector_id' => $sector->id,
            'category' => 'BELUM DINILAI', 'status' => 'draft',
        ]))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $matching->id)
            ->assertJsonPath('data.0.latest_score', null)->assertJsonPath('data.0.evaluation_status', 'draft');

        $this->getJson('/api/umkms?status=none')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Toko Belum');
    }

    public function test_default_score_sort_and_explicit_ascending_keep_null_last_and_id_breaks_ties(): void
    {
        $owner = $this->authenticate();
        $withoutEvaluation = Umkm::factory()->for($owner)->create();
        $zero = Umkm::factory()->for($owner)->create();
        $higher = Umkm::factory()->for($owner)->create();
        $tied = Umkm::factory()->for($owner)->create();
        $zero->evaluations()->create(['status' => 'final', 'average_score' => 0, 'category' => 'KRITIS']);
        foreach ([$higher, $tied] as $umkm) {
            $umkm->evaluations()->create(['status' => 'final', 'average_score' => 80, 'category' => 'BAIK']);
        }
        Umkm::factory()->create();

        $this->getJson('/api/umkms')->assertOk()
            ->assertJsonPath('data.0.id', $higher->id)->assertJsonPath('data.1.id', $tied->id)
            ->assertJsonPath('data.2.id', $zero->id)->assertJsonPath('data.3.id', $withoutEvaluation->id)
            ->assertJsonPath('meta.total', 4)->assertJsonPath('meta.per_page', 10);
        $this->getJson('/api/umkms?sort=score&direction=asc')->assertOk()
            ->assertJsonPath('data.0.id', $zero->id)->assertJsonPath('data.1.id', $higher->id)
            ->assertJsonPath('data.2.id', $tied->id)->assertJsonPath('data.3.id', $withoutEvaluation->id);
    }

    public function test_name_and_omzet_sort_and_pagination_expose_stable_meta(): void
    {
        $owner = $this->authenticate();
        $first = Umkm::factory()->for($owner)->create(['name' => 'Alfa', 'omzet' => 10]);
        $second = Umkm::factory()->for($owner)->create(['name' => 'Beta', 'omzet' => 30]);
        Umkm::factory()->for($owner)->create(['name' => 'Gamma', 'omzet' => 20]);

        $this->getJson('/api/umkms?sort=name&direction=asc&per_page=1&page=2')
            ->assertOk()->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.from', 2)->assertJsonPath('meta.to', 2)->assertJsonPath('meta.total', 3)
            ->assertJsonStructure(['links' => ['first', 'last', 'prev', 'next']]);
        $this->getJson('/api/umkms?sort=omzet&direction=asc')->assertOk()->assertJsonPath('data.0.id', $first->id);
        $this->getJson('/api/umkms?sort=omzet&direction=desc')->assertOk()->assertJsonPath('data.0.id', $second->id);
        $this->getJson('/api/umkms?search=TidakCocok')->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonPath('meta.from', null)->assertJsonPath('meta.total', 0);
    }

    public function test_options_are_authenticated_and_share_canonical_config(): void
    {
        $this->getJson('/api/umkms/options')->assertUnauthorized();
        $this->authenticate();

        $response = $this->getJson('/api/umkms/options')->assertOk()
            ->assertJsonCount(5, 'data.sectors')->assertJsonCount(38, 'data.provinces')
            ->assertJsonCount(6, 'data.categories');
        $this->assertSame(config('provinces'), $response->json('data.provinces'));
        $this->assertSame(config('umkm.scoring.categories.0'), $response->json('data.categories.0'));
        $this->assertSame('BELUM DINILAI', $response->json('data.categories.5.label'));
    }

    public function test_invalid_store_update_and_list_inputs_return_json_422_without_mutation(): void
    {
        $owner = $this->authenticate();
        $umkm = Umkm::factory()->for($owner)->create();

        $this->postJson('/api/umkms', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'province', 'sector_id', 'omzet']);
        $this->postJson('/api/umkms', $this->payload([
            'name' => str_repeat('a', 121), 'province' => 'Asing', 'sector_id' => 99999,
            'omzet' => 9007199254740992, 'user_id' => 99999,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['name', 'province', 'sector_id', 'omzet', 'user_id']);
        $this->postJson('/api/umkms', $this->payload(['omzet' => -1]))
            ->assertUnprocessable()->assertJsonValidationErrors('omzet');
        $this->postJson('/api/umkms', $this->payload(['omzet' => '15.000.000']))
            ->assertUnprocessable()->assertJsonValidationErrors('omzet');
        $this->putJson('/api/umkms/'.$umkm->id, ['name' => 'Tidak Lengkap'])
            ->assertUnprocessable()->assertJsonValidationErrors(['province', 'sector_id', 'omzet']);
        $this->patchJson('/api/umkms/'.$umkm->id, ['unknown' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->getJson('/api/umkms?'.http_build_query([
            'sort' => 'name desc;drop table users', 'direction' => 'sideways', 'page' => 0,
            'per_page' => 101, 'status' => 'bad', 'category' => 'bad', 'province' => 'bad', 'sector_id' => 99999,
        ]))->assertUnprocessable()->assertJsonValidationErrors([
            'sort', 'direction', 'page', 'per_page', 'status', 'category', 'province', 'sector_id',
        ]);
        $this->assertDatabaseCount('umkms', 1);
    }

    public function test_foreign_update_with_invalid_payload_and_delete_are_consistent_404(): void
    {
        $foreign = Umkm::factory()->create(['name' => 'Rahasia']);
        $this->authenticate();

        $this->patchJson('/api/umkms/'.$foreign->id, ['name' => null, 'record_id' => 99999])
            ->assertNotFound()->assertExactJson(['message' => 'Data tidak ditemukan.']);
        $this->putJson('/api/umkms/'.$foreign->id, [])->assertNotFound();
        $this->deleteJson('/api/umkms/'.$foreign->id)->assertNotFound();
        $this->deleteJson('/api/umkms/999999')->assertNotFound();
        $this->getJson('/api/umkms')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame('Rahasia', $foreign->fresh()->name);
    }

    private function authenticate(?User $user = null): User
    {
        $user ??= User::factory()->create();
        $this->app['auth']->forgetGuards();
        $this->withToken($user->createToken('test')->plainTextToken);

        return $user;
    }

    private function payload(array $changes = []): array
    {
        return array_replace([
            'name' => 'Toko Uji', 'province' => 'DKI Jakarta',
            'sector_id' => Sector::query()->firstOrFail()->id, 'omzet' => 0,
        ], $changes);
    }
}
