<?php

declare(strict_types=1);

namespace App\Support\Billing;

use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * A plan as the application understands it, read from configuration.
 *
 * The key is the application's own name for the plan and never changes; the
 * Stripe price id belongs to Stripe and does change — between environments,
 * and every time a price is revised. Everything internal keys off the former,
 * which is what makes a price change survivable.
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
        public ?string $priceId,
        public int $price,
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
     * The plan a Stripe price belongs to, if any.
     *
     * The only place that goes the other way — from Stripe's identifier back
     * to ours — and it exists for webhooks, which speak only Stripe.
     */
    public static function forPriceId(?string $priceId): ?self
    {
        if ($priceId === null || $priceId === '') {
            return null;
        }

        foreach (self::all() as $plan) {
            if ($plan->priceId === $priceId) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * @return array<string, self>
     */
    public static function all(): array
    {
        /** @var array<string, array{name: string, price_id: string|null, price: int, limits: array<string, int|null>, features: array<int, string>, api_rate_limit: int}> $configured */
        $configured = Config::array('billing.plans');

        $plans = [];

        foreach ($configured as $key => $plan) {
            $plans[$key] = new self(
                key: $key,
                name: $plan['name'],
                // Variável de ambiente declarada e vazia chega como string
                // vazia, não como null. Sem normalizar, o plano seria
                // oferecido e só falharia na Stripe, com um erro que não diz
                // nada a quem clicou.
                priceId: ($plan['price_id'] ?? '') === '' ? null : $plan['price_id'],
                price: $plan['price'],
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
     * A plan with no Stripe price is not purchasable whatever the config says;
     * offering it would send someone to a checkout that cannot exist.
     *
     * @return array<string, self>
     */
    public static function purchasable(): array
    {
        return array_filter(self::all(), fn (self $plan): bool => $plan->priceId !== null);
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
        return $this->priceId === null;
    }
}
