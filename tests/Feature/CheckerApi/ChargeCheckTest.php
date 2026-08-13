<?php

declare(strict_types=1);

use App\Actions\CheckerApi\ExecuteCheck;
use App\Models\Team;
use App\Support\TenantManager;
use Modules\CheckerApi\Models\Check;
use Modules\CheckerApi\Models\CheckExecution;
use Modules\CheckerApi\Support\CheckInput;
use Modules\CheckerApi\Support\FakeProvider;
use Modules\CheckerApi\Support\ProviderRegistry;
use Modules\CheckerApi\Support\ProviderResult;
use Modules\Wallet\Actions\CreditWallet;
use Modules\Wallet\Enums\WalletHoldStatus;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Exceptions\InsufficientBalanceException;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Models\WalletHold;
use Modules\Wallet\Models\WalletTransaction;

function setupTeam(int $balance): Team
{
    $team = Team::factory()->create();
    resolve(TenantManager::class)->set($team);

    // CreditWallet exige valor positivo (é o próprio contrato da action); o
    // caso de saldo zero só precisa do time existir, sem lançamento nenhum.
    if ($balance > 0) {
        resolve(CreditWallet::class)->handle($team, $balance, WalletTransactionType::Recharge, 'Fundo');
    }

    return $team;
}

function chargeInput(): CheckInput
{
    return CheckInput::fromArray(['cpf' => '12345678901']);
}

it('sucesso debita o preço e liga a cobrança à execução', function (): void {
    $team = setupTeam(1000);
    resolve(ProviderRegistry::class)->register(FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano'])));
    $check = Check::factory()->priced(350)->create(['provider_key' => 'fake']);

    $execution = resolve(ExecuteCheck::class)->handle($check, chargeInput());

    expect(Wallet::forTeam($team)->balance_cents)->toBe(650)
        ->and(WalletHold::query()->where('status', WalletHoldStatus::Settled->value)->count())->toBe(1);

    $charge = WalletTransaction::query()->where('type', WalletTransactionType::Charge->value)->firstOrFail();
    expect($charge->reference_id)->toBe($execution->id)
        ->and($charge->reference_type)->toBe($execution->getMorphClass());
});

it('nada encontrado não cobra e solta o hold', function (): void {
    $team = setupTeam(1000);
    resolve(ProviderRegistry::class)->register(FakeProvider::returning(ProviderResult::noData()));
    $check = Check::factory()->priced(350)->create(['provider_key' => 'fake']);

    resolve(ExecuteCheck::class)->handle($check, chargeInput());

    expect(Wallet::forTeam($team)->balance_cents)->toBe(1000)
        ->and(Wallet::forTeam($team)->available())->toBe(1000)
        ->and(WalletHold::query()->where('status', WalletHoldStatus::Released->value)->count())->toBe(1);
});

it('falha técnica não cobra e solta o hold', function (): void {
    $team = setupTeam(1000);
    resolve(ProviderRegistry::class)->register(FakeProvider::returning(ProviderResult::failed('timeout')));
    $check = Check::factory()->priced(350)->create(['provider_key' => 'fake']);

    resolve(ExecuteCheck::class)->handle($check, chargeInput());

    expect(Wallet::forTeam($team)->balance_cents)->toBe(1000)
        ->and(Wallet::forTeam($team)->available())->toBe(1000);
});

it('saldo insuficiente recusa antes de executar e não registra execução', function (): void {
    $team = setupTeam(100); // menos que o preço
    $provider = FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano']));
    resolve(ProviderRegistry::class)->register($provider);
    $check = Check::factory()->priced(350)->create(['provider_key' => 'fake']);

    expect(fn () => resolve(ExecuteCheck::class)->handle($check, chargeInput()))
        ->toThrow(InsufficientBalanceException::class);

    expect(CheckExecution::query()->count())->toBe(0)
        ->and($provider->calls())->toBe(0);
});

it('check de preço zero não toca a carteira', function (): void {
    $team = setupTeam(0);
    resolve(ProviderRegistry::class)->register(FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano'])));
    $check = Check::factory()->priced(0)->create(['provider_key' => 'fake']);

    $execution = resolve(ExecuteCheck::class)->handle($check, chargeInput());

    expect($execution->outcome->value)->toBe('success')
        ->and(WalletTransaction::query()->count())->toBe(0)
        ->and(WalletHold::query()->count())->toBe(0);
});
