<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Support\Facades\Http;
use Modules\Payments\Actions\CreateRecharge;
use Modules\Payments\Contracts\PaymentGateway;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Exceptions\RechargeAmountException;
use Modules\Payments\Gateways\VirtualPayGateway;
use Modules\Payments\Models\PaymentIntent;
use Modules\Payments\Support\PayerData;
use Modules\Wallet\Models\WalletTransaction;

beforeEach(function (): void {
    // O binding real (Task 8) resolve com credenciais de config (string vazia
    // em teste); este teste não exercita as credenciais, só precisa de uma
    // instância concreta para a action resolver via Http::fake().
    app()->bind(PaymentGateway::class, fn (): VirtualPayGateway => new VirtualPayGateway('https://api.virtualpay.online/v2', 'cid', 'csecret'));
});

it('cria a recarga com um valor válido: intent pendente com dados do gateway', function (): void {
    Http::fake(['*/transactions' => Http::response([
        'id' => 'uuid-1', 'copy_paste' => '000201...', 'qr_code' => 'base64==', 'status' => 'pending',
    ], 201)]);

    $team = Team::factory()->create();

    $intent = resolve(CreateRecharge::class)->handle($team, 5000, PayerData::for('Fulano', 'f@e.com'));

    expect($intent->status)->toBe(PaymentStatus::Pending)
        ->and($intent->amount_cents)->toBe(5000)
        ->and($intent->external_id)->toBe('uuid-1')
        ->and($intent->copy_paste)->toBe('000201...')
        ->and($intent->reference)->toStartWith('RECHARGE-'.$team->id.'-');

    expect(WalletTransaction::acrossTenants()->count())->toBe(0);
});

it('lança RechargeAmountException para valor abaixo do mínimo, sem chamar o gateway', function (): void {
    Http::fake();

    $team = Team::factory()->create();

    expect(fn () => resolve(CreateRecharge::class)->handle($team, 500, PayerData::for('Fulano', 'f@e.com')))
        ->toThrow(RechargeAmountException::class);

    Http::assertNothingSent();
    expect(PaymentIntent::acrossTenants()->count())->toBe(0);
});

it('lança RechargeAmountException para valor acima do máximo, sem chamar o gateway', function (): void {
    Http::fake();

    $team = Team::factory()->create();

    expect(fn () => resolve(CreateRecharge::class)->handle($team, 200000, PayerData::for('Fulano', 'f@e.com')))
        ->toThrow(RechargeAmountException::class);

    Http::assertNothingSent();
    expect(PaymentIntent::acrossTenants()->count())->toBe(0);
});
