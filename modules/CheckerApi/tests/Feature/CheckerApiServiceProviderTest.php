<?php

declare(strict_types=1);

use Modules\CheckerApi\Support\CheckAvailability;
use Modules\CheckerApi\Support\ProviderRegistry;

it('resolve o registro como singleton', function (): void {
    expect(resolve(ProviderRegistry::class))->toBe(resolve(ProviderRegistry::class));
});

it('resolve os serviços de suporte do módulo', function (): void {
    expect(resolve(CheckAvailability::class))->toBeInstanceOf(CheckAvailability::class);
});
