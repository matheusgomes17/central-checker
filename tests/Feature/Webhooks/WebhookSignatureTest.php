<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Support\Webhooks\WebhookSignature;
use Tests\TestCase;

/**
 * The signature is the whole promise: it is what lets a receiver tell our
 * delivery from any other POST to the same URL.
 */
class WebhookSignatureTest extends TestCase
{
    private const string SECRET = 'whsec_test';

    private const string PAYLOAD = '{"type":"member.joined"}';

    public function test_a_signature_we_produced_verifies(): void
    {
        $header = WebhookSignature::header(self::PAYLOAD, self::SECRET, 1_700_000_000);

        $this->assertTrue(WebhookSignature::verify($header, self::PAYLOAD, self::SECRET, 1_700_000_000));
    }

    public function test_a_changed_body_does_not_verify(): void
    {
        $header = WebhookSignature::header(self::PAYLOAD, self::SECRET, 1_700_000_000);

        $this->assertFalse(WebhookSignature::verify($header, '{"type":"member.removed"}', self::SECRET, 1_700_000_000));
    }

    public function test_another_secret_does_not_verify(): void
    {
        $header = WebhookSignature::header(self::PAYLOAD, self::SECRET, 1_700_000_000);

        $this->assertFalse(WebhookSignature::verify($header, self::PAYLOAD, 'whsec_outro', 1_700_000_000));
    }

    /**
     * The reason the timestamp is signed rather than merely sent.
     *
     * A signature over the body alone stays valid forever, so anyone who
     * captures one delivery can replay it a year later.
     */
    public function test_an_old_delivery_is_refused(): void
    {
        $header = WebhookSignature::header(self::PAYLOAD, self::SECRET, 1_700_000_000);

        $this->assertFalse(WebhookSignature::verify(
            $header,
            self::PAYLOAD,
            self::SECRET,
            1_700_000_000 + WebhookSignature::TOLERANCE + 1,
        ));
    }

    /**
     * Moving the timestamp to get past the freshness check has to break the
     * signature, otherwise the check buys nothing.
     */
    public function test_a_delivery_given_a_new_timestamp_does_not_verify(): void
    {
        $header = WebhookSignature::header(self::PAYLOAD, self::SECRET, 1_700_000_000);
        $now = 1_700_009_999;

        $forged = preg_replace('/^t=\d+/', 't='.$now, $header);

        $this->assertIsString($forged);
        $this->assertFalse(WebhookSignature::verify($forged, self::PAYLOAD, self::SECRET, $now));
    }

    public function test_a_malformed_header_is_refused(): void
    {
        foreach (['', 'garbage', 't=abc,v1=x', 'v1=x', 't=1700000000'] as $header) {
            $this->assertFalse(
                WebhookSignature::verify($header, self::PAYLOAD, self::SECRET, 1_700_000_000),
                "aceitou o cabeçalho: {$header}",
            );
        }
    }
}
