<?php

declare(strict_types=1);

use Modules\Pagamentos\Support\GatewayCharge;

it('carrega os dados da cobrança criada', function (): void {
    $charge = GatewayCharge::from('uuid-1', '000201...', 'base64==', 'pending');

    expect($charge->externalId())->toBe('uuid-1')
        ->and($charge->copyPaste())->toBe('000201...')
        ->and($charge->qrCode())->toBe('base64==')
        ->and($charge->status())->toBe('pending');
});
