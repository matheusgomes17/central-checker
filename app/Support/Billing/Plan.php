<?php

declare(strict_types=1);

namespace App\Support\Billing;

use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * A plan as the application understands it, read from configuration.
 *
 * The key is the application's internal name for the plan and never changes.
 * The price is in cents and drives the Pix charge; period_days is how long a
 * paid period lasts once the charge is confirmed.
 */
final readonly class Plan
{
    /**
     * @param  array<string, int|null>  $limits
     * @param  array<int, string>  $features
     */
    private function __construct(
        public string $key,
        public string $name,
        public int $price,
        public int $periodDays,
        public array $limits,
        public array $features,
        public int $apiRateLimit,
    ) {}

    public static function find(string $key): self
    {
        $plans = self::all();

        if (! array_key_exists($key, $plans)) {
            throw new InvalidArgumentException("Plano [{$key}] não existe.");
        }

        return $plans[$key];
    }

    /**
     * The plan a team without an active subscription is on.
     *
     * Exists so that "no subscription" never means "no limits": not paying is
     * a state of the product, not the absence of one.
     */
    public static function default(): self
    {
        return self::find(Config::string('billing.default_plan'));
    }

    /**
     * @return array<string, self>
     */
    public static function all(): array
    {
        /** @var array<string, array{name: string, price: int, period_days: int, limits: array<string, int|null>, features: array<int, string>, api_rate_limit: int}> $configured */
        $configured = Config::array('billing.plans');

        $plans = [];

        foreach ($configured as $key => $plan) {
            $plans[$key] = new self(
                key: $key,
                name: $plan['name'],
                price: $plan['price'],
                periodDays: $plan['period_days'],
                limits: $plan['limits'],
                features: $plan['features'],
                apiRateLimit: $plan['api_rate_limit'],
            );
        }

        return $plans;
    }

    /**
     * Plans a team can actually subscribe to.
     *
     * A plan you can subscribe to is one that costs something; the free plan
     * is where a team already is without any purchase.
     *
     * @return array<string, self>
     */
    public static function purchasable(): array
    {
        return array_filter(self::all(), fn (self $plan): bool => $plan->price > 0);
    }

    /**
     * The limit for a resource, or null when there is none.
     *
     * Null and zero are different answers on purpose: null is "no ceiling",
     * zero is "none allowed".
     */
    public function limit(string $resource): ?int
    {
        return $this->limits[$resource] ?? null;
    }

    public function hasFeature(string $feature): bool
    {
        return in_array($feature, $this->features, true);
    }

    public function isFree(): bool
    {
        return $this->price === 0;
    }
}
