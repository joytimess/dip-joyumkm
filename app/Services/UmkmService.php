<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Sector;
use App\Models\Umkm;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class UmkmService
{
    public function __construct(private readonly OpenWaNumberCheckService $openWa) {}

    public function queryWithLatest(User $user): Builder
    {
        return $user->accessibleUmkms()
            ->leftJoin('evaluations as latest_evaluation', function (JoinClause $join): void {
                $join->on('latest_evaluation.umkm_id', '=', 'umkms.id')
                    ->where('latest_evaluation.id', '=', function (QueryBuilder $query): void {
                        $query->selectRaw('MAX(id)')
                            ->from('evaluations')
                            ->whereColumn('evaluations.umkm_id', 'umkms.id');
                    });
            })
            ->select('umkms.*')
            ->addSelect([
                'latest_evaluation.id as evaluation_id',
                'latest_evaluation.status as evaluation_status',
                'latest_evaluation.average_score as latest_score',
                'latest_evaluation.category as latest_category',
                'latest_evaluation.ins_score',
                'latest_evaluation.ect_score',
                'latest_evaluation.ops_score',
            ]);
    }

    public function owned(User $user, int $id): Umkm
    {
        return $this->queryWithLatest($user)
            ->with('sector')
            ->where('umkms.id', $id)
            ->firstOrFail();
    }

    public function list(User $user, array $filters): LengthAwarePaginator
    {
        $query = $this->queryWithLatest($user)->with('sector');
        if (isset($filters['search']) && $filters['search'] !== '') {
            $search = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['search']);
            $query->whereRaw("umkms.name LIKE ? ESCAPE '!'", ['%'.$search.'%']);
        }
        foreach (['sector_id', 'province'] as $field) {
            if (isset($filters[$field])) {
                $query->where('umkms.'.$field, $filters[$field]);
            }
        }
        if (isset($filters['category'])) {
            if ($filters['category'] === config('umkm.scoring.unrated.label')) {
                $query->whereNull('latest_evaluation.category');
            } else {
                $query->where('latest_evaluation.category', $filters['category']);
            }
        }
        if (isset($filters['status'])) {
            if ($filters['status'] === 'none') {
                $query->whereNull('latest_evaluation.id');
            } else {
                $query->where('latest_evaluation.status', $filters['status']);
            }
        }
        $columns = ['score' => 'latest_evaluation.average_score', 'name' => 'umkms.name', 'omzet' => 'umkms.omzet'];
        $sort = $filters['sort'] ?? 'score';
        if ($sort === 'score') {
            $query->orderByRaw('latest_evaluation.average_score IS NULL ASC');
        }

        return $query->orderBy($columns[$sort], $filters['direction'] ?? 'desc')
            ->orderBy('umkms.id')
            ->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1))
            ->withQueryString();
    }

    public function store(User $user, array $data): Umkm
    {
        $status = $this->openWa->check($data['whatsapp_number']);
        $umkm = $user->umkms()->make(Arr::except($data, ['notification_consent']))->forceFill([
            'wa_registration_status' => $status,
            'wa_checked_at' => $status === OpenWaNumberCheckService::UNCHECKED ? null : now(),
        ]);
        if ($data['notification_consent'] ?? true) {
            $umkm->forceFill($this->notificationConsentAttributes($user));
        }
        try {
            $umkm->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => ['Nama UMKM sudah digunakan.']]);
        }

        return $this->owned($user, $umkm->id);
    }

    public function update(User $user, int $id, array $data): Umkm
    {
        try {
            DB::transaction(function () use ($user, $id, $data): void {
                $umkm = $user->accessibleUmkms()->whereKey($id)->lockForUpdate()->firstOrFail();
                $intent = $data['notification_consent'] ?? null;
                if ($intent === 'grant' && $umkm->user_id !== $user->id) {
                    // Persetujuan notifikasi hanya boleh diberikan pemilik; akses global tidak menggantikan consent.
                    throw new AccessDeniedHttpException('Persetujuan notifikasi hanya dapat diberikan oleh pemilik UMKM.');
                }
                if ($intent === 'grant' && trim((string) $umkm->business_email) !== trim((string) $data['expected_business_email'])) {
                    throw new ConflictHttpException('Email usaha telah berubah. Muat ulang data UMKM sebelum memberikan persetujuan.');
                }

                $umkm->fill(Arr::except($data, [
                    'notification_consent', 'expected_business_email', 'consented_business_email',
                ]));
                if ($umkm->isDirty('whatsapp_number')) {
                    $umkm->forceFill(['wa_registration_status' => OpenWaNumberCheckService::UNCHECKED, 'wa_checked_at' => null]);
                }
                if ($intent === 'revoke') {
                    $umkm->forceFill([
                        'notification_consent_at' => null,
                        'notification_consent_version' => null,
                        'notification_consent_by_user_id' => null,
                    ]);
                }
                if ($intent === 'grant' && (trim((string) $umkm->business_email) !== $data['consented_business_email']
                    || ! filter_var(trim((string) $umkm->business_email), FILTER_VALIDATE_EMAIL))) {
                    throw ValidationException::withMessages(['notification_consent' => ['Setujui alamat email usaha yang valid dan akan disimpan.']]);
                }
                $umkm->save();

                // Simpan grant setelah hook perubahan email mencabut consent lama, dalam transaksi yang sama.
                if ($intent === 'grant') {
                    $umkm->forceFill($this->notificationConsentAttributes($user))->save();
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => ['Nama UMKM sudah digunakan.']]);
        }

        return $this->owned($user, $id);
    }

    /** @return array{notification_consent_at: Carbon, notification_consent_version: string, notification_consent_by_user_id: int} */
    private function notificationConsentAttributes(User $user): array
    {
        return [
            'notification_consent_at' => now()->utc(),
            'notification_consent_version' => 'v1',
            'notification_consent_by_user_id' => $user->id,
        ];
    }

    /**
     * Periksa ulang WhatsApp untuk nomor yang tersimpan dan simpan hasilnya hanya jika nomor tersebut belum berubah.
     */
    public function recheckWhatsapp(User $user, int $id, string $number): Umkm
    {
        $umkm = $this->owned($user, $id);
        if ($umkm->whatsapp_number !== $number) {
            throw new ConflictHttpException('Nomor WhatsApp telah berubah. Muat ulang data UMKM sebelum memeriksa ulang.');
        }

        $status = $this->openWa->check($number);
        $saved = $user->accessibleUmkms()->whereKey($id)->where('whatsapp_number', $number)->update([
            'wa_registration_status' => $status,
            'wa_checked_at' => $status === OpenWaNumberCheckService::UNCHECKED ? null : now(),
        ]);
        if ($saved === 0) {
            throw new ConflictHttpException('Nomor WhatsApp telah berubah. Muat ulang data UMKM sebelum memeriksa ulang.');
        }

        return $this->owned($user, $id);
    }

    public function delete(User $user, int $id): array
    {
        $this->owned($user, $id)->delete();

        return ['message' => 'UMKM dan seluruh evaluasinya berhasil dihapus.'];
    }

    public function options(): array
    {
        return [
            'sectors' => Sector::query()->orderBy('name')->get(['id', 'name'])->toArray(),
            'provinces' => config('provinces'),
            'categories' => [
                ...config('umkm.scoring.categories'),
                ['minimum' => null, ...config('umkm.scoring.unrated')],
            ],
        ];
    }
}
