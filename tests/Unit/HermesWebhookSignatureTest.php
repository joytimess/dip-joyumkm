<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\HermesWebhookSender;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\HermesWebhookReceiver;

class HermesWebhookSignatureTest extends TestCase
{
    public function test_fixture_validates_exact_bytes_signature_identity_and_timestamp_window(): void
    {
        $id = '550e8400-e29b-41d4-a716-446655440000';
        $timestamp = 1795341600;
        $body = '{"event_id":"'.$id.'", "event_type":"evaluation.finalized","event_version":1}';
        $signature = HermesWebhookSender::signature($timestamp, $body, HermesWebhookReceiver::SECRET);

        $this->assertTrue(HermesWebhookReceiver::accepts($body, $id, (string) $timestamp, $signature, $timestamp + 300));
        $this->assertFalse(HermesWebhookReceiver::accepts($body."\n", $id, (string) $timestamp, $signature, $timestamp));
        $this->assertFalse(HermesWebhookReceiver::accepts($body, $id, (string) ($timestamp + 1), $signature, $timestamp));
        $this->assertFalse(HermesWebhookReceiver::accepts($body, $id, (string) $timestamp, $signature, $timestamp + 301));
        $this->assertFalse(HermesWebhookReceiver::accepts($body, $id, (string) $timestamp, $signature, $timestamp - 301));
        $this->assertFalse(HermesWebhookReceiver::accepts($body, 'different-event', (string) $timestamp, $signature, $timestamp));
        $wrongSecret = HermesWebhookSender::signature($timestamp, $body, 'different-key');
        $this->assertFalse(HermesWebhookReceiver::accepts($body, $id, (string) $timestamp, $wrongSecret, $timestamp));
    }
}
