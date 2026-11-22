<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GeocodingService
{
    private const PUBLIC_HOST = 'nominatim.openstreetmap.org';

    public function search(string $query): array
    {
        $query = Str::squish($query);

        return $this->lookup('search', ['q' => $query, 'countrycodes' => 'id', 'limit' => 5], mb_strtolower($query));
    }

    public function reverse(float $latitude, float $longitude): ?array
    {
        $coordinates = ['lat' => round($latitude, 6), 'lon' => round($longitude, 6)];

        return $this->lookup('reverse', $coordinates, implode(',', $coordinates))[0] ?? null;
    }

    private function lookup(string $path, array $parameters, string $lookupKey): array
    {
        $url = rtrim((string) config('services.geocoding.url'), '/');
        $parts = parse_url($url);
        $host = strtolower(rtrim($parts['host'] ?? '', '.'));
        $agent = trim((string) config('services.geocoding.user_agent'));
        $store = config('services.geocoding.cache_store');
        $public = $host === self::PUBLIC_HOST;

        abort_unless(filter_var($url, FILTER_VALIDATE_URL) && ($parts['scheme'] ?? null) === 'https'
            && in_array($host, config('services.geocoding.allowed_hosts'), true)
            && array_intersect_key($parts, array_flip(['query', 'fragment', 'user', 'pass'])) === []
            && $agent !== '' && ! preg_match('/[\x00-\x1F\x7F]/', $agent), 503);
        abort_if($public && ! in_array(config("cache.stores.{$store}.driver"), ['database', 'redis'], true), 503);

        $cache = Cache::store($store);
        $cacheKey = 'joyumkm:geocoding:'.hash('sha256', $url.'|'.$path.'|'.$lookupKey);
        $cached = $cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $lock = $public ? $cache->lock('joyumkm:geocoding:public:request', 15) : null;
        abort_if($lock !== null && ! $lock->get(), 429);

        try {
            if ($lock !== null) {
                $cached = $cache->get($cacheKey);
                if ($cached !== null) {
                    return $cached;
                }
                abort_if((float) $cache->get('joyumkm:geocoding:public:next', 0) > microtime(true), 429);
            }

            try {
                $timeout = max(1, min(5, (int) config('services.geocoding.timeout')));
                $response = Http::acceptJson()->withUserAgent($agent)->withoutRedirecting()
                    ->connectTimeout($timeout)->timeout($timeout)
                    ->get($url.'/'.$path, $parameters + ['format' => 'jsonv2', 'accept-language' => 'id']);
            } catch (ConnectionException) {
                abort(503);
            } finally {
                if ($lock !== null) {
                    $cache->put('joyumkm:geocoding:public:next', microtime(true) + 1, 15);
                }
            }

            $data = $response->json();
            if ($path === 'reverse' && $response->status() === 404) {
                $result = [];
            } else {
                abort_unless($response->ok() && is_array($data) && empty($data['error']), 503);
                $result = $path === 'search' ? $this->searchResults($data) : $this->reverseResult($data);
            }
            $cache->put($cacheKey, $result, $result === [] ? 300 : 86_400);

            return $result;
        } finally {
            $lock?->release();
        }
    }

    private function searchResults(array $data): array
    {
        abort_unless(array_is_list($data), 503);
        $results = [];
        foreach (array_slice($data, 0, 5) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $label = $this->text($row, 'display_name', 500);
            $latitude = $row['lat'] ?? null;
            $longitude = $row['lon'] ?? null;
            if ($label !== null && is_numeric($latitude) && abs((float) $latitude) <= 90
                && is_numeric($longitude) && abs((float) $longitude) <= 180) {
                $results[] = ['latitude' => (float) $latitude, 'longitude' => (float) $longitude, 'label' => $label];
            }
        }

        return $results;
    }

    private function reverseResult(array $data): array
    {
        $address = $data['address'] ?? [];
        if (! is_array($address) || ($address['country_code'] ?? 'id') !== 'id') {
            return [];
        }

        $state = mb_strtolower($this->text($address, 'state') ?? '');
        $state = preg_replace('/^provinsi\s+/u', '', $state);
        $state = preg_replace('/^sumatra\b/u', 'sumatera', $state);
        $state = match ($state) {
            'jakarta', 'daerah khusus jakarta', 'daerah khusus ibukota jakarta' => 'dki jakarta',
            'yogyakarta', 'daerah istimewa yogyakarta' => 'di yogyakarta',
            'bangka belitung' => 'kepulauan bangka belitung',
            default => $state,
        };
        $province = array_column(array_map(fn (string $name): array => [mb_strtolower($name), $name], config('provinces')), 1, 0)[$state] ?? null;
        $city = $this->text($address, 'city');
        if ($province === 'DKI Jakarta' && mb_strtolower($city ?? '') === 'jakarta') {
            $city = null;
        }
        $city ??= $this->text($address, 'municipality') ?? $this->text($address, 'county') ?? $this->text($address, 'town');
        $district = $this->text($address, 'city_district') ?? $this->text($address, 'district');
        $postcode = $this->text($address, 'postcode');
        $result = [
            'province' => $province,
            'city' => $city,
            'address' => $this->text($data, 'display_name', 2000),
            'district' => $district === $city ? null : $district,
            'subdistrict' => $this->text($address, 'village') ?? $this->text($address, 'suburb') ?? $this->text($address, 'quarter'),
            'postal_code' => preg_match('/\A[0-9]{5}\z/', $postcode ?? '') ? $postcode : null,
        ];

        return array_filter($result) === [] ? [] : [$result];
    }

    private function text(array $data, string $key, int $limit = 120): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $limit) : null;
    }
}
