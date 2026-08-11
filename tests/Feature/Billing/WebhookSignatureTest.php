<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The webhook endpoint is unauthenticated by design — Stripe has no session
 * and no cookie. What stands in for authentication is the signature on the
 * payload, and the other billing tests run with no signing secret configured,
 * which switches that middleware off entirely.
 *
 * So it is verified here, on its own, with a signature computed the way Stripe
 * computes one. No network involved: the algorithm is public and the secret is
 * ours.
 */
class WebhookSignatureTest extends TestCase
{
    use RefreshDatabase;

    private const string SECRET = 'whsec_test_secret_for_this_suite';

    protected function setUp(): void
    {
        parent::setUp();

        config(['cashier.webhook.secret' => self::SECRET, 'cashier.webhook.tolerance' => 300]);
    }

    public function test_a_correctly_signed_payload_is_accepted(): void
    {
        $payload = $this->payload();

        $this->call(
            'POST',
            route('cashier.webhook'),
            server: ['HTTP_STRIPE_SIGNATURE' => $this->signature($payload, $this->now())],
            content: $payload,
        )->assertOk();
    }

    public function test_an_unsigned_payload_is_refused(): void
    {
        $this->call('POST', route('cashier.webhook'), content: $this->payload())
            ->assertForbidden();
    }

    public function test_a_payload_signed_with_the_wrong_secret_is_refused(): void
    {
        $payload = $this->payload();
        $timestamp = $this->now();

        $wrong = 'v1='.hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_not_the_secret');

        $this->call(
            'POST',
            route('cashier.webhook'),
            server: ['HTTP_STRIPE_SIGNATURE' => "t={$timestamp},{$wrong}"],
            content: $payload,
        )->assertForbidden();
    }

    /**
     * The timestamp is part of what is signed, which is what stops a captured
     * payload from being replayed forever.
     */
    public function test_a_signature_older_than_the_tolerance_is_refused(): void
    {
        $payload = $this->payload();
        $stale = $this->now() - 3600;

        $this->call(
            'POST',
            route('cashier.webhook'),
            server: ['HTTP_STRIPE_SIGNATURE' => $this->signature($payload, $stale)],
            content: $payload,
        )->assertForbidden();
    }

    /**
     * A signature that matches a different body must not travel with this one.
     */
    public function test_a_signature_from_another_payload_is_refused(): void
    {
        $timestamp = $this->now();
        $signature = $this->signature($this->payload('evt_one'), $timestamp);

        $this->call(
            'POST',
            route('cashier.webhook'),
            server: ['HTTP_STRIPE_SIGNATURE' => $signature],
            content: $this->payload('evt_two'),
        )->assertForbidden();
    }

    /**
     * The timestamp property on Carbon reads as float|int|string because it
     * comes through __get, so the seconds are fetched by method instead.
     */
    private function now(): int
    {
        return (int) now()->getTimestamp();
    }

    private function payload(string $id = 'evt_test'): string
    {
        return (string) json_encode([
            'id' => $id,
            'type' => 'invoice.payment_failed',
            'data' => ['object' => ['customer' => 'cus_unknown', 'amount_due' => 100, 'attempt_count' => 1]],
        ]);
    }

    private function signature(string $payload, int $timestamp): string
    {
        $hash = hash_hmac('sha256', "{$timestamp}.{$payload}", self::SECRET);

        return "t={$timestamp},v1={$hash}";
    }
}
