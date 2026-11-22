<?php

declare(strict_types=1);

namespace Tests\Fixtures;

final class HermesWebhookReceiver
{
    public const SECRET = 'p4-01-fixture-secret';

    public static function accepts(string $body, string $eventId, string $timestamp, string $signature, int $now): bool
    {
        if (! preg_match('/^\d{10}$/D', $timestamp) || abs($now - (int) $timestamp) > 300) {
            return false;
        }

        $event = json_decode($body, true);

        return $eventId !== ''
            && ($event['event_id'] ?? null) === $eventId
            && ($event['event_type'] ?? null) === 'evaluation.finalized'
            && ($event['event_version'] ?? null) === 1
            && hash_equals(hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET), $signature);
    }
}

if (PHP_SAPI === 'cli-server') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $_SERVER['REQUEST_URI'] !== '/joyumkm-events') {
        http_response_code(404);

        return;
    }

    $deliveryId = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';
    $accepted = HermesWebhookReceiver::accepts(
        file_get_contents('php://input'),
        $deliveryId,
        $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? '',
        $_SERVER['HTTP_X_WEBHOOK_SIGNATURE_V2'] ?? '',
        time(),
    );
    $gate = getenv('JOYUMKM_HERMES_STUB_GATE');
    if ($accepted && $gate && file_get_contents($gate) === '') {
        file_put_contents($gate, 'accepted', LOCK_EX);
        $deadline = microtime(true) + 12;
        while (! is_file($gate.'.release') && microtime(true) < $deadline) {
            usleep(20_000);
            clearstatcache();
        }
    }

    if (! $accepted) {
        http_response_code(401);

        return;
    }

    header('Content-Type: application/json');
    http_response_code(202);
    echo json_encode([
        'status' => 'accepted',
        'route' => 'joyumkm-events',
        'event' => 'evaluation.finalized',
        'delivery_id' => $deliveryId,
    ]);
}
