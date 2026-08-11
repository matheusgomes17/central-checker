<?php

declare(strict_types=1);

use Modules\Carteira\Enums\WalletHoldStatus;
use Modules\Carteira\Enums\WalletTransactionType;

it('classifica os tipos que creditam a carteira', function (): void {
    expect(WalletTransactionType::Recharge->isCredit())->toBeTrue()
        ->and(WalletTransactionType::Bonus->isCredit())->toBeTrue()
        ->and(WalletTransactionType::Adjustment->isCredit())->toBeTrue()
        ->and(WalletTransactionType::Charge->isCredit())->toBeFalse();
});

it('tem os estados de hold esperados', function (): void {
    expect(WalletHoldStatus::Active->value)->toBe('active')
        ->and(WalletHoldStatus::Settled->value)->toBe('settled')
        ->and(WalletHoldStatus::Released->value)->toBe('released');
});
