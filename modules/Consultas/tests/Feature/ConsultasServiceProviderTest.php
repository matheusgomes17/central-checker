<?php

declare(strict_types=1);

use Modules\Consultas\Support\ConsultaAvailability;
use Modules\Consultas\Support\ProviderRegistry;

it('resolve o registro como singleton', function (): void {
    expect(resolve(ProviderRegistry::class))->toBe(resolve(ProviderRegistry::class));
});

it('resolve os serviços de suporte do módulo', function (): void {
    expect(resolve(ConsultaAvailability::class))->toBeInstanceOf(ConsultaAvailability::class);
});
