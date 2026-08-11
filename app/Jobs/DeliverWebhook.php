<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\WebhookEndpoint;
use App\Support\Webhooks\WebhookSignature;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Http;
use Override;
use Throwable;

/**
 * One delivery to one endpoint.
 *
 * One job per endpoint rather than one job that loops: a team with five
 * endpoints where the third is down should still have the fourth and fifth
 * delivered, and a loop that throws to trigger the retry would redeliver the
 * ones that already succeeded.
 *
 * ShouldQueueAfterCommit holds the delivery until the transaction commits.
 * Without it the delivery can leave before the write it announces is durable,
 * and a rollback afterwards leaves the customer holding an event for something
 * that never happened — worse than no event, because they acted on it.
 */
class DeliverWebhook extends TenantJob implements ShouldQueueAfterCommit
{
    /**
     * The endpoint may be deleted while the delivery waits in the queue. That
     * is an answer, not a failure.
     */
    public bool $deleteWhenMissingModels = true;

    public int $tries = 6;

    /**
     * @param  array{id: string, type: string, created_at: string, data: array<string, mixed>}  $body
     */
    public function __construct(
        public WebhookEndpoint $endpoint,
        public array $body,
    ) {
        $this->onQueue('default');
    }

    /**
     * Hours, not minutes.
     *
     * The thing being retried is a server we do not run, and a deploy or an
     * outage on their side lasts longer than the pauses that make sense for
     * our own failures.
     *
     * @return array<int, int>
     */
    #[Override]
    public function backoff(): array
    {
        return [10, 60, 300, 1800, 7200];
    }

    #[Override]
    public function retryUntil(): DateTimeInterface
    {
        return now()->addDay();
    }

    /**
     * @return array<int, string>
     */
    #[Override]
    public function tags(): array
    {
        return ['webhook', 'endpoint:'.$this->endpoint->id, 'team:'.$this->endpoint->team_id];
    }

    /**
     * Records the endpoint as unreachable once the attempts run out.
     */
    public function failed(?Throwable $exception): void
    {
        $this->restoreTenant();

        $failures = $this->endpoint->consecutive_failures + 1;

        $this->endpoint->forceFill([
            'consecutive_failures' => $failures,
            'is_active' => $failures < WebhookEndpoint::FAILURE_LIMIT,
        ])->save();
    }

    protected function run(): void
    {
        $payload = json_encode($this->body, JSON_THROW_ON_ERROR);

        // Assinatura nova a cada tentativa: o receptor recusa o que for velho
        // demais, e reenviar a assinatura da primeira tentativa três horas
        // depois seria entregar algo que ele deve rejeitar. O id dentro do
        // corpo é que se mantém, para o receptor conseguir descartar repetido.
        $timestamp = now()->getTimestamp();

        $response = Http::withHeaders([
            WebhookSignature::HEADER => WebhookSignature::header($payload, $this->endpoint->secret, $timestamp),
            'Content-Type' => 'application/json',
            'User-Agent' => config()->string('app.name').'-Webhooks/1',
        ])
            ->timeout(10)
            ->connectTimeout(5)
            ->withBody($payload, 'application/json')
            ->post($this->endpoint->url);

        $this->endpoint->forceFill([
            'last_status' => $response->status(),
            'last_delivered_at' => now(),
        ])->save();

        // throw: é o que faz a fila reagendar. Tratar aqui e retornar seria
        // transformar uma entrega falha em uma entrega esquecida.
        $response->throw();

        $this->endpoint->forceFill(['consecutive_failures' => 0])->save();
    }
}
