<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Jobs\DeliverWebhook;
use App\Models\Team;
use App\Models\WebhookEndpoint;
use App\Support\TenantManager;
use App\Support\Webhooks\WebhookSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhookDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private WebhookEndpoint $endpoint;

    protected function setUp(): void
    {
        parent::setUp();

        $team = Team::factory()->create(['is_personal' => false]);

        $this->endpoint = WebhookEndpoint::factory()->create(['team_id' => $team->id]);

        resolve(TenantManager::class)->set($team);
    }

    public function test_a_delivery_is_signed_with_the_endpoint_secret(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        $this->deliver();

        Http::assertSent(fn (Request $request): bool => WebhookSignature::verify(
            $this->signatureOf($request),
            $request->body(),
            $this->endpoint->secret,
            now()->getTimestamp(),
        ));
    }

    /**
     * A receiver holding the wrong secret must not be able to accept the
     * delivery, otherwise the signature is decoration.
     */
    public function test_the_signature_does_not_verify_under_another_secret(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        $this->deliver();

        Http::assertSent(fn (Request $request): bool => ! WebhookSignature::verify(
            $this->signatureOf($request),
            $request->body(),
            'whsec_outro',
            now()->getTimestamp(),
        ));
    }

    public function test_a_successful_delivery_is_recorded_and_clears_the_failure_count(): void
    {
        Http::fake(['*' => Http::response('', 202)]);

        $this->endpoint->forceFill(['consecutive_failures' => 4])->save();

        $this->deliver();

        $this->endpoint->refresh();

        $this->assertSame(202, $this->endpoint->last_status);
        $this->assertNotNull($this->endpoint->last_delivered_at);
        $this->assertSame(0, $this->endpoint->consecutive_failures);
    }

    /**
     * The job has to throw, because throwing is what makes the queue schedule
     * the retry. Catching the error and returning would turn a failed delivery
     * into a forgotten one.
     */
    public function test_a_rejected_delivery_throws_so_the_queue_retries(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);

        $this->expectException(RequestException::class);

        $this->deliver();
    }

    public function test_the_retries_back_off_over_hours(): void
    {
        $job = new DeliverWebhook($this->endpoint, $this->body());

        $this->assertSame([10, 60, 300, 1800, 7200], $job->backoff());
        $this->assertSame(6, $job->tries);
        $this->assertTrue($job->retryUntil() > now()->addHours(20));
    }

    public function test_an_endpoint_that_keeps_failing_is_switched_off(): void
    {
        $this->endpoint->forceFill(['consecutive_failures' => WebhookEndpoint::FAILURE_LIMIT - 2])->save();

        $job = new DeliverWebhook($this->endpoint, $this->body());

        $job->failed(null);
        $this->endpoint->refresh();
        $this->assertTrue($this->endpoint->is_active);

        new DeliverWebhook($this->endpoint, $this->body())->failed(null);

        $this->endpoint->refresh();
        $this->assertFalse($this->endpoint->is_active);
    }

    public function test_the_job_is_tagged_for_the_dashboard(): void
    {
        $job = new DeliverWebhook($this->endpoint, $this->body());

        $this->assertContains('endpoint:'.$this->endpoint->id, $job->tags());
        $this->assertContains('team:'.$this->endpoint->team_id, $job->tags());
    }

    private function deliver(): void
    {
        new DeliverWebhook($this->endpoint, $this->body())->handle();
    }

    /**
     * @return array{id: string, type: string, created_at: string, data: array<string, mixed>}
     */
    private function body(): array
    {
        return ['id' => 'evt_1', 'type' => 'member.joined', 'created_at' => now()->toIso8601String(), 'data' => []];
    }

    private function signatureOf(Request $request): string
    {
        $header = $request->header(WebhookSignature::HEADER);

        return is_string($header[0] ?? null) ? $header[0] : '';
    }
}
