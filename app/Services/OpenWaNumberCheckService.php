<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class OpenWaNumberCheckService
{
    public const REGISTERED = 'registered';

    public const UNREGISTERED = 'unregistered';

    public const UNCHECKED = 'unchecked';

    public const NUMBER_PATTERN = '/\A628[0-9]{8,11}\z/';

    public static function normalize(mixed $value): mixed
    {
        if (! is_string($value) || ! preg_match('/\A(?:08|\+?62[ -]?8)(?:[ -]?[0-9]){8,11}\z/', trim($value))) {
            return $value;
        }

        $number = str_replace([' ', '-', '+'], '', trim($value));

        return str_starts_with($number, '0') ? '62'.substr($number, 1) : $number;
    }

    public function check(string $number): string
    {
        $baseUrl = rtrim((string) config('services.openwa.base_url'), '/');
        $session = (string) config('services.openwa.session_id');
        $key = (string) config('services.openwa.api_key');
        $url = parse_url($baseUrl);

        if (! config('services.openwa.enabled') || trim($key) === '' || preg_match('/[\x00-\x1F\x7F]/', $key)
            || ! filter_var($baseUrl, FILTER_VALIDATE_URL)
            || ($url['scheme'] ?? null) !== 'https' || ! isset($url['host'])
            || array_intersect_key($url, array_flip(['user', 'pass', 'query', 'fragment'])) !== []
            || ! preg_match('/\A[a-zA-Z0-9_-]+\z/', $session)
            || ! preg_match(self::NUMBER_PATTERN, $number)) {
            return self::UNCHECKED;
        }

        $timeout = min(3, max(1, (int) config('services.openwa.timeout')));

        try {
            $response = Http::acceptJson()
                ->withHeader('X-API-Key', $key)
                ->withoutRedirecting()
                ->connectTimeout($timeout)
                ->timeout($timeout)
                ->get($baseUrl.'/sessions/'.$session.'/contacts/check/'.$number);
        } catch (ConnectionException) {
            return self::UNCHECKED;
        }

        if (! $response->ok()) {
            return self::UNCHECKED;
        }

        $data = $response->json();
        if (! is_array($data) || ! is_bool($data['exists'] ?? null) || ! empty($data['error'])
            || ($data['success'] ?? null) === false
            || (array_key_exists('number', $data) && $data['number'] !== $number)) {
            return self::UNCHECKED;
        }

        return $data['exists'] ? self::REGISTERED : self::UNREGISTERED;
    }
}
