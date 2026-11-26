<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\ResponseTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class HermesPredictionService
{
    /**
     * @param  array<string, int>  $scores
     * @return array{total_score: int, category: string, recommendation: string}
     */
    public function predict(array $scores): array
    {
        $secret = (string) config('services.hermes_prediction.secret');
        $url = (string) config('services.hermes_prediction.url');
        abort_unless(config('services.hermes_prediction.enabled') === true && strlen(trim($secret)) >= 32, 503);

        $destination = parse_url($url);
        abort_unless(
            filter_var($url, FILTER_VALIDATE_URL)
            && ! str_contains($url, '\\')
            && is_array($destination)
            && ! array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($destination))
            && isset($destination['path'])
            && ($destination['scheme'] === 'https' || (
                $destination['scheme'] === 'http' && $destination['host'] === '127.0.0.1'
                && app()->environment(['local', 'testing'])
            )),
            503,
        );

        $body = json_encode($scores, JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp."\n".$destination['path']."\n".$body, $secret);

        try {
            $response = Http::acceptJson()
                ->connectTimeout(0.5)->timeout(2)
                ->withoutRedirecting()
                ->withOptions(['proxy' => ''])
                ->withHeaders([
                    'X-JoyUMKM-Prediction-Timestamp' => $timestamp,
                    'X-JoyUMKM-Prediction-Signature' => $signature,
                ])
                ->withBody($body, 'application/json')
                ->post($url);
        } catch (ConnectionException $exception) {
            $cause = $exception->getPrevious();
            $timedOut = $cause instanceof ConnectTimeoutException || $cause instanceof NetworkTimeoutException
                || $cause instanceof ResponseTimeoutException;
            abort($timedOut ? 504 : 503);
        }

        $status = $response->status();
        if ($status === 400) {
            throw ValidationException::withMessages(['scores' => ['Prediksi menolak skor penilaian. Periksa kembali keenam faktor.']]);
        }
        if (in_array($status, [503, 504], true)) {
            abort($status);
        }
        abort_unless($status === 200, 502);

        $result = $response->json();
        abort_unless(
            is_array($result)
            && ($result['success'] ?? null) === true
            && ($result['total_score'] ?? null) === array_sum($scores)
            && in_array($result['category'] ?? null, ['Kurang Sehat', 'Cukup Sehat', 'Sehat', 'Sangat Sehat'], true)
            && is_string($result['recommendation'] ?? null)
            && trim($result['recommendation']) !== ''
            && strlen($result['recommendation']) <= 500,
            502,
        );

        return Arr::only($result, ['total_score', 'category', 'recommendation']);
    }
}
