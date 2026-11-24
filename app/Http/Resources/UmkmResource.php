<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\OpenWaNumberCheckService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UmkmResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $category = $this->latest_category ?? config('umkm.scoring.unrated.label');

        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'province' => $this->province,
            'sector' => ['id' => (int) $this->sector->id, 'name' => $this->sector->name],
            'omzet' => (int) $this->omzet,
            'latest_score' => $this->latest_score === null ? null : (float) $this->latest_score,
            'category' => $category,
            'recommendation' => $category,
            'evaluation_status' => $this->evaluation_status ?? 'none',
            'evaluation_id' => $this->evaluation_id === null ? null : (int) $this->evaluation_id,
            $this->mergeWhen($request->route('id') !== null || $request->isMethod('POST'), fn (): array => [
                ...$this->resource->only([
                    'omzet_period', 'business_capital', 'year_founded', 'employee_count', 'nib', 'description',
                    'city', 'address', 'district', 'subdistrict', 'postal_code', 'latitude', 'longitude',
                    'owner_name', 'whatsapp_number', 'business_email', 'pic_name', 'website_url', 'social_media_url',
                ]),
                'whatsapp_registration_status' => $this->wa_registration_status ?? OpenWaNumberCheckService::UNCHECKED,
                'whatsapp_checked_at' => $this->wa_checked_at?->toISOString(),
                'notification_consent_active' => $this->resource->hasNotificationConsent(),
                'business_email_valid' => filter_var(trim((string) $this->business_email), FILTER_VALIDATE_EMAIL) !== false,
            ]),
        ];
    }
}
