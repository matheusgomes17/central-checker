<?php

declare(strict_types=1);

use App\Actions\CheckerApi\ExecutarCheck;
use App\Models\PlanCheck;
use App\Models\PlanSubscription;
use App\Models\Team;
use App\Support\TenantManager;
use Modules\Carteira\Actions\CreditWallet;
use Modules\Carteira\Enums\WalletTransactionType;
use Modules\Carteira\Models\Wallet;
use Modules\Carteira\Models\WalletTransaction;
use Modules\CheckerApi\Exceptions\FranquiaExhaustedException;
use Modules\CheckerApi\Models\Check;
use Modules\CheckerApi\Models\CheckExecution;
use Modules\CheckerApi\Support\CheckInput;
use Modules\CheckerApi\Support\FakeProvider;
use Modules\CheckerApi\Support\ProviderRegistry;
use Modules\CheckerApi\Support\ProviderResult;

/**
 * Team on the 'pro' plan, active subscription — the period Franquia anchors
 * on. TenantManager is set so ExecutarCheck and Franquia both resolve this
 * team as the current tenant.
 */
function setupPlanTeam(): Team
{
    $team = Team::factory()->create();
    resolve(TenantManager::class)->set($team);
    PlanSubscription::factory()->active()->create(['team_id' => $team->id, 'plan' => 'pro']);

    return $team;
}

function franquiaInput(): CheckInput
{
    return CheckInput::fromArray(['cpf' => '12345678901']);
}

it('franquia ilimitada executa sem tocar a carteira', function (): void {
    $team = setupPlanTeam();
    resolve(ProviderRegistry::class)->register(FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano'])));
    $check = Check::factory()->create(['provider_key' => 'fake']);
    PlanCheck::factory()->unlimited()->create(['plan' => 'pro', 'check_id' => $check->id]);

    resolve(ExecutarCheck::class)->handle($check, franquiaInput());
    resolve(ExecutarCheck::class)->handle($check, franquiaInput());
    resolve(ExecutarCheck::class)->handle($check, franquiaInput());

    expect(CheckExecution::query()->count())->toBe(3)
        ->and(WalletTransaction::query()->count())->toBe(0);
});

it('dentro da franquia executa grátis e esgota no teto', function (): void {
    $team = setupPlanTeam();
    resolve(ProviderRegistry::class)->register(FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano'])));
    $check = Check::factory()->create(['provider_key' => 'fake']);
    PlanCheck::factory()->create(['plan' => 'pro', 'check_id' => $check->id, 'quota' => 2]);

    resolve(ExecutarCheck::class)->handle($check, franquiaInput());
    resolve(ExecutarCheck::class)->handle($check, franquiaInput());

    expect(fn () => resolve(ExecutarCheck::class)->handle($check, franquiaInput()))
        ->toThrow(FranquiaExhaustedException::class);

    expect(CheckExecution::query()->count())->toBe(2)
        ->and(WalletTransaction::query()->count())->toBe(0);
});

it('franquia esgotada cai para crédito quando o check tem preço', function (): void {
    $team = setupPlanTeam();
    resolve(CreditWallet::class)->handle($team, 1000, WalletTransactionType::Recharge, 'Fundo');
    resolve(ProviderRegistry::class)->register(FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano'])));
    $check = Check::factory()->priced(350)->create(['provider_key' => 'fake']);
    PlanCheck::factory()->create(['plan' => 'pro', 'check_id' => $check->id, 'quota' => 1]);

    resolve(ExecutarCheck::class)->handle($check, franquiaInput());
    expect(Wallet::forTeam($team)->balance_cents)->toBe(1000);

    resolve(ExecutarCheck::class)->handle($check, franquiaInput());
    expect(Wallet::forTeam($team)->balance_cents)->toBe(650);
});

it('nada encontrado sob franquia não consome o teto', function (): void {
    $team = setupPlanTeam();
    resolve(ProviderRegistry::class)->register(FakeProvider::returning(
        ProviderResult::noData(),
        ProviderResult::success(['nome' => 'Fulano']),
    ));
    $check = Check::factory()->create(['provider_key' => 'fake']);
    PlanCheck::factory()->create(['plan' => 'pro', 'check_id' => $check->id, 'quota' => 1]);

    $first = resolve(ExecutarCheck::class)->handle($check, franquiaInput());
    $second = resolve(ExecutarCheck::class)->handle($check, franquiaInput());

    expect($first->outcome->value)->toBe('no_data')
        ->and($second->outcome->value)->toBe('success')
        ->and(WalletTransaction::query()->count())->toBe(0);
});

it('check não coberto pelo plano segue no crédito normal', function (): void {
    $team = setupPlanTeam();
    resolve(CreditWallet::class)->handle($team, 1000, WalletTransactionType::Recharge, 'Fundo');
    resolve(ProviderRegistry::class)->register(FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano'])));
    $check = Check::factory()->priced(350)->create(['provider_key' => 'fake']);
    // Nenhum PlanCheck ligando 'pro' a este check — não coberto.

    resolve(ExecutarCheck::class)->handle($check, franquiaInput());

    expect(Wallet::forTeam($team)->balance_cents)->toBe(650);
});
