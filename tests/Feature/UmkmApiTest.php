<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Question;
use App\Models\Sector;
use App\Models\Umkm;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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

    public function test_registration_defaults_to_email_delivery_and_respects_an_explicit_opt_out(): void
    {
        $owner = $this->authenticate();

        $created = $this->postJson('/api/umkms', $this->payload())
            ->assertCreated()->assertJsonPath('data.notification_consent_active', true);
        $umkm = $owner->umkms()->findOrFail($created->json('data.id'));
        $this->assertEquals($owner->id, $umkm->notification_consent_by_user_id);
        $this->assertNotNull($umkm->notification_consent_at);

        $this->postJson('/api/umkms', $this->payload(['name' => 'Tanpa laporan', 'notification_consent' => false]))
            ->assertCreated()->assertJsonPath('data.notification_consent_active', false);
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

    #[TestWith(['0812 3456-7890', true, 'registered'])]
    #[TestWith(['+6281234567890', false, 'unregistered'])]
    #[TestWith(['6281234567890', '503', 'unchecked'])]
    #[TestWith(['081234567890', 'invalid', 'unchecked'])]
    #[TestWith(['081234567890', 'timeout', 'unchecked'])]
    #[TestWith(['081234567890', 'wrong-number', 'unchecked'])]
    #[TestWith(['081234567890', 'disconnected', 'unchecked'])]
    public function test_whatsapp_check_and_create_use_only_the_server_result(string $input, bool|string $result, string $status): void
    {
        $this->authenticate();
        $this->fakeOpenWa($result);

        $this->postJson('/api/umkms/whatsapp-check', ['whatsapp_number' => $input])
            ->assertOk()->assertJsonPath('data.normalized_number', '6281234567890')
            ->assertJsonPath('data.status', $status)
            ->assertDontSee('test-only-openwa-key');

        $saved = $this->postJson('/api/umkms', $this->payload([
            'whatsapp_number' => $input,
            'wa_registration_status' => 'forged',
            'whatsapp_registration_status' => 'forged',
            'wa_checked_at' => '2000-01-01',
        ]))->assertCreated()
            ->assertJsonPath('data.whatsapp_number', '6281234567890')
            ->assertJsonPath('data.whatsapp_registration_status', $status)
            ->assertDontSee('test-only-openwa-key');

        $checkedAt = $saved->json('data.whatsapp_checked_at');
        if ($status === 'unchecked') {
            $this->assertNull($checkedAt);
        } else {
            $this->assertSame(now()->utc()->toDateString(), substr($checkedAt, 0, 10));
        }
        $this->assertDatabaseHas('umkms', [
            'id' => $saved->json('data.id'), 'whatsapp_number' => '6281234567890', 'wa_registration_status' => $status,
        ]);
        if ($result !== 'timeout') {
            Http::assertSentCount(2);
            Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
                && $request->url() === 'https://openwa.test/api/sessions/test-session/contacts/check/6281234567890'
                && $request->hasHeader('X-API-Key', 'test-only-openwa-key'));
        }
    }

    public function test_whatsapp_check_requires_auth_and_is_throttled_independently_of_creation(): void
    {
        $payload = ['whatsapp_number' => '081234567890'];
        $this->postJson('/api/umkms/whatsapp-check', $payload)->assertUnauthorized();
        $this->authenticate();
        $this->fakeOpenWa(false);
        $this->postJson('/api/umkms/whatsapp-check', ['whatsapp_number' => '0812abc34567890'])
            ->assertUnprocessable()->assertJsonValidationErrors('whatsapp_number');
        Http::assertNothingSent();
        for ($attempt = 1; $attempt < 10; $attempt++) {
            $this->postJson('/api/umkms/whatsapp-check', $payload)->assertOk();
        }
        $this->postJson('/api/umkms/whatsapp-check', $payload)->assertTooManyRequests();
        $this->postJson('/api/umkms', $this->payload())->assertCreated();
        $this->authenticate();
        $this->postJson('/api/umkms/whatsapp-check', $payload)->assertOk();
    }

    public function test_new_fields_validate_and_legacy_partial_updates_keep_existing_data(): void
    {
        $owner = $this->authenticate();
        $legacy = Umkm::factory()->for($owner)->create(['omzet' => 100000]);
        $evaluation = $legacy->evaluations()->create(['status' => 'final', 'average_score' => 80, 'category' => 'BAIK']);

        $this->getJson('/api/umkms/'.$legacy->id)->assertOk()
            ->assertJsonPath('data.omzet_period', null)
            ->assertJsonPath('data.whatsapp_registration_status', 'unchecked');
        $this->patchJson('/api/umkms/'.$legacy->id, ['city' => '  Denpasar  '])->assertOk()
            ->assertJsonPath('data.city', 'Denpasar')->assertJsonPath('data.omzet', 100000)
            ->assertJsonPath('data.omzet_period', null)->assertJsonPath('data.evaluation_id', $evaluation->id);
        $this->postJson('/api/umkms', $this->payload([
            'omzet_period' => 'weekly', 'city' => '  ', 'address' => ' ', 'owner_name' => '',
            'whatsapp_number' => '0812ABC', 'business_email' => 'bad', 'latitude' => 91, 'longitude' => null,
            'postal_code' => '1234', 'year_founded' => now()->year + 1, 'employee_count' => -1,
            'nib' => '12A', 'website_url' => 'ftp://example.com', 'social_media_url' => 'bad',
        ]))->assertUnprocessable()->assertJsonValidationErrors([
            'omzet_period', 'city', 'address', 'owner_name', 'whatsapp_number', 'business_email',
            'latitude', 'longitude', 'postal_code', 'year_founded', 'employee_count', 'nib', 'website_url', 'social_media_url',
        ]);
        $this->patchJson('/api/umkms/'.$legacy->id, ['latitude' => 0])->assertUnprocessable()
            ->assertJsonValidationErrors('longitude');
        $this->patchJson('/api/umkms/'.$legacy->id, ['latitude' => 0, 'longitude' => 110.5])->assertOk()
            ->assertJsonPath('data.latitude', '0.0000000')->assertJsonPath('data.longitude', '110.5000000');
        $this->patchJson('/api/umkms/'.$legacy->id, ['latitude' => null])->assertUnprocessable();
        $this->patchJson('/api/umkms/'.$legacy->id, ['latitude' => null, 'longitude' => null])->assertOk()
            ->assertJsonPath('data.latitude', null)->assertJsonPath('data.longitude', null);
    }

    public function test_business_capital_is_required_on_create_and_optional_for_legacy_updates(): void
    {
        $owner = $this->authenticate();
        foreach ([null, -1, '1500.5', 'abc'] as $invalid) {
            $this->postJson('/api/umkms', $this->payload(['business_capital' => $invalid]))
                ->assertUnprocessable()->assertJsonValidationErrors('business_capital');
        }
        $this->assertDatabaseCount('umkms', 0);

        $id = $this->postJson('/api/umkms', $this->payload(['name' => 'Modal Nol', 'business_capital' => 0]))
            ->assertCreated()->assertJsonPath('data.business_capital', 0)->json('data.id');
        $this->getJson('/api/umkms/'.$id)->assertOk()->assertJsonPath('data.business_capital', 0);

        $legacy = Umkm::factory()->for($owner)->create(['business_capital' => null, 'omzet_period' => null]);
        $this->getJson('/api/umkms/'.$legacy->id)->assertOk()->assertJsonPath('data.business_capital', null);
        $this->patchJson('/api/umkms/'.$legacy->id, ['business_capital' => null, 'omzet_period' => ''])->assertOk()
            ->assertJsonPath('data.business_capital', null)->assertJsonPath('data.omzet_period', null);
        $this->patchJson('/api/umkms/'.$legacy->id, ['business_capital' => 'abc'])
            ->assertUnprocessable()->assertJsonValidationErrors('business_capital');
        $this->patchJson('/api/umkms/'.$legacy->id, ['business_capital' => 25000000, 'omzet_period' => 'yearly'])->assertOk()
            ->assertJsonPath('data.business_capital', 25000000)->assertJsonPath('data.omzet_period', 'yearly');
        $this->assertDatabaseHas('umkms', ['id' => $legacy->id, 'business_capital' => 25000000, 'omzet_period' => 'yearly']);
    }

    public function test_changing_whatsapp_number_resets_previous_result(): void
    {
        $this->authenticate();
        $this->fakeOpenWa(true);
        $id = $this->postJson('/api/umkms', $this->payload())->assertCreated()->json('data.id');
        $this->patchJson('/api/umkms/'.$id, ['whatsapp_number' => '+6281234567890'])->assertOk()
            ->assertJsonPath('data.whatsapp_registration_status', 'registered');
        $this->patchJson('/api/umkms/'.$id, ['whatsapp_number' => '081222333444'])->assertOk()
            ->assertJsonPath('data.whatsapp_number', '6281222333444')
            ->assertJsonPath('data.whatsapp_registration_status', 'unchecked')
            ->assertJsonPath('data.whatsapp_checked_at', null);
        Http::assertSentCount(1);
    }

    public function test_whatsapp_recheck_persists_result_only_for_the_current_number(): void
    {
        $owner = $this->authenticate();
        $this->fakeOpenWa(true);
        $umkm = Umkm::factory()->for($owner)->create(['whatsapp_number' => '6281234567890']);

        $this->postJson('/api/umkms/'.$umkm->id.'/whatsapp-check', ['whatsapp_number' => '081234567890'])
            ->assertOk()->assertJsonPath('data.whatsapp_registration_status', 'registered')
            ->assertDontSee('test-only-openwa-key');
        $this->assertDatabaseHas('umkms', ['id' => $umkm->id, 'wa_registration_status' => 'registered']);

        $this->postJson('/api/umkms/'.$umkm->id.'/whatsapp-check', ['whatsapp_number' => '081222333444'])
            ->assertConflict();
        $this->fakeOpenWa('timeout');
        $this->postJson('/api/umkms/'.$umkm->id.'/whatsapp-check', ['whatsapp_number' => '081234567890'])
            ->assertOk()->assertJsonPath('data.whatsapp_registration_status', 'unchecked')
            ->assertJsonPath('data.whatsapp_checked_at', null);
    }

    public function test_whatsapp_recheck_is_limited_to_owner_and_never_checks_foreign_records(): void
    {
        $this->authenticate();
        $this->fakeOpenWa(true);
        $foreign = Umkm::factory()->for(User::factory())->create(['whatsapp_number' => '6281234567890']);

        $this->postJson('/api/umkms/'.$foreign->id.'/whatsapp-check', ['whatsapp_number' => '081234567890'])
            ->assertNotFound();
        Http::assertNothingSent();
        $this->postJson('/api/umkms/'.$foreign->id.'/whatsapp-check', ['whatsapp_number' => 'bad'])
            ->assertUnprocessable()->assertJsonValidationErrors('whatsapp_number');
    }

    public function test_profile_metadata_is_available_in_detail_but_list_keeps_its_existing_shape(): void
    {
        $owner = $this->authenticate();
        $umkm = Umkm::factory()->for($owner)->withBusinessProfile()->create([
            'employee_count' => 0, 'nib' => '0123456789012', 'postal_code' => '01234',
        ]);
        $this->getJson('/api/umkms/'.$umkm->id)->assertOk()
            ->assertJsonPath('data.omzet_period', 'monthly')
            ->assertJsonPath('data.employee_count', 0)
            ->assertJsonPath('data.nib', '0123456789012')
            ->assertJsonPath('data.postal_code', '01234')
            ->assertJsonPath('data.business_email', $umkm->business_email);
        $this->getJson('/api/umkms')->assertOk()->assertJsonMissingPath('data.0.business_email')
            ->assertJsonPath('data.0.id', $umkm->id);
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
            ->assertJsonValidationErrors(['name', 'province', 'sector_id', 'omzet', 'omzet_period',
                'city', 'address', 'latitude', 'longitude', 'owner_name', 'whatsapp_number', 'business_email']);
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
            'omzet_period' => 'monthly', 'business_capital' => 0, 'city' => 'Jakarta', 'address' => 'Jalan Uji 10',
            'owner_name' => 'Pemilik Uji', 'whatsapp_number' => '081234567890',
            'business_email' => 'usaha@example.com',
            'latitude' => 0, 'longitude' => 106.825,
        ], $changes);
    }

    private function fakeOpenWa(bool|string $result): void
    {
        config(['services.openwa' => [
            'enabled' => true, 'base_url' => 'https://openwa.test/api',
            'session_id' => 'test-session', 'api_key' => 'test-only-openwa-key', 'timeout' => 3,
        ]]);
        Http::preventStrayRequests();
        Http::fake([
            'https://openwa.test/api/*' => match ($result) {
                'timeout' => Http::failedConnection('Synthetic timeout test-only-openwa-key'),
                '503' => Http::response(['message' => 'test-only-openwa-key'], 503),
                'invalid' => Http::response(['exists' => 'false', 'message' => 'test-only-openwa-key']),
                'wrong-number' => Http::response(['number' => '628999999999', 'exists' => false]),
                'disconnected' => Http::response(['exists' => false, 'success' => false, 'error' => 'Session disconnected']),
                default => Http::response(['number' => '6281234567890', 'exists' => $result]),
            },
        ]);
    }
}
