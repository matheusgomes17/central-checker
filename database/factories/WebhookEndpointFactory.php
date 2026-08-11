<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WebhookEvent;
use App\Models\Team;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookEndpoint>
 */
class WebhookEndpointFactory extends Factory
{
    protected $model = WebhookEndpoint::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'url' => 'https://'.fake()->domainName().'/webhooks',
            'description' => fake()->words(3, true),
            'secret' => WebhookEndpoint::newSecret(),
            'events' => WebhookEvent::values(),
            'is_active' => true,
        ];
    }

    public function inactive(): self
    {
        return $this->state(['is_active' => true, 'consecutive_failures' => 0])->state(['is_active' => false]);
    }

    /**
     * @param  array<int, WebhookEvent>  $events
     */
    public function subscribedTo(array $events): self
    {
        return $this->state(['events' => array_map(fn (WebhookEvent $e): string => $e->value, $events)]);
    }
}
