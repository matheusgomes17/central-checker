<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;

it('expõe a configuração da VirtualPay e da recarga', function (): void {
    expect(Config::string('services.virtualpay.base_url'))->toBe('https://api.virtualpay.online/v2')
        ->and(Config::integer('billing.recharge.min_cents'))->toBe(1000)
        ->and(Config::integer('billing.recharge.max_cents'))->toBe(100000)
        ->and(Config::boolean('billing.virtualpay_integration'))->toBeFalse();
});
