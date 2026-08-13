<?php

declare(strict_types=1);

use App\Actions\CheckerApi\ExecutarCheck;
use App\Enums\TeamRole;
use App\Models\PlanCheck;
use App\Models\PlanSubscription;
use App\Models\Team;
use App\Models\User;
use App\Models\UserCheckOverride;
use App\Support\Billing\Allowance;
use App\Support\TenantManager;
use Modules\CheckerApi\Exceptions\CheckBlockedForUserException;
use Modules\CheckerApi\Models\Check;
use Modules\CheckerApi\Models\CheckExecution;
use Modules\CheckerApi\Support\CheckInput;
use Modules\CheckerApi\Support\FakeProvider;
use Modules\CheckerApi\Support\ProviderRegistry;
use Modules\CheckerApi\Support\ProviderResult;
use Modules\Wallet\Actions\CreditWallet;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Models\WalletTransaction;

/**
 * A team with a member user, wallet optionally funded. UserFactory's
 * afterCreating switches the tenant to the user's own personal team, so the
 * tenant is (re)set to $team only after the user exists.
 *
 * @return array{Team, User}
 */
function setupOverrideTeam(int $balance = 1000): array
{
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user);
    $user->assignTeamRole($team, TeamRole::Member);
    resolve(TenantManager::class)->set($team);

    if ($balance > 0) {
        resolve(CreditWallet::class)->handle($team, $balance, WalletTransactionType::Recharge, 'Fundo');
    }

    return [$team, $user];
}

function overrideInput(): CheckInput
{
    return CheckInput::fromArray(['cpf' => '12345678901']);
}

it('override bloqueado impede a execução e não toca a carteira', function (): void {
    [$team, $user] = setupOverrideTeam(0);
    resolve(ProviderRegistry::class)->register(FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano'])));
    $check = Check::factory()->priced(350)->create(['provider_key' => 'fake']);
    UserCheckOverride::factory()->blocked()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'check_id' => $check->id,
    ]);

    expect(fn () => resolve(ExecutarCheck::class)->handle($check, overrideInput(), $user))
        ->toThrow(CheckBlockedForUserException::class);

    expect(CheckExecution::query()->count())->toBe(0)
        ->and(WalletTransaction::query()->count())->toBe(0);
});

it('override bloqueado e concedido ao mesmo tempo bloqueia — block vence grant', function (): void {
    [$team, $user] = setupOverrideTeam(0);
    resolve(ProviderRegistry::class)->register(FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano'])));
    $check = Check::factory()->priced(350)->create(['provider_key' => 'fake']);
    UserCheckOverride::factory()->blocked()->granted()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'check_id' => $check->id,
    ]);

    expect(fn () => resolve(ExecutarCheck::class)->handle($check, overrideInput(), $user))
        ->toThrow(CheckBlockedForUserException::class);

    expect(CheckExecution::query()->count())->toBe(0)
        ->and(WalletTransaction::query()->count())->toBe(0);
});

it('override concedido executa grátis mesmo com preço não coberto pelo plano', function (): void {
    [$team, $user] = setupOverrideTeam();
    resolve(ProviderRegistry::class)->register(FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano'])));
    $check = Check::factory()->priced(350)->create(['provider_key' => 'fake']);
    // Nenhum PlanCheck ligando o time a este check — franquia não cobre.
    UserCheckOverride::factory()->granted()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'check_id' => $check->id,
    ]);

    $execution = resolve(ExecutarCheck::class)->handle($check, overrideInput(), $user);

    expect(Wallet::forTeam($team)->balance_cents)->toBe(1000)
        ->and($execution->outcome->value)->toBe('success')
        ->and(CheckExecution::query()->count())->toBe(1);
});

it('override com preço custom cobra o valor do override, não o do check', function (): void {
    [$team, $user] = setupOverrideTeam();
    resolve(ProviderRegistry::class)->register(FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano'])));
    $check = Check::factory()->priced(350)->create(['provider_key' => 'fake']);
    UserCheckOverride::factory()->priced(100)->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'check_id' => $check->id,
    ]);

    resolve(ExecutarCheck::class)->handle($check, overrideInput(), $user);

    expect(Wallet::forTeam($team)->balance_cents)->toBe(900);
});

it('sem override mantém o comportamento de crédito do D-b', function (): void {
    [$team, $user] = setupOverrideTeam();
    resolve(ProviderRegistry::class)->register(FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano'])));
    $check = Check::factory()->priced(350)->create(['provider_key' => 'fake']);

    resolve(ExecutarCheck::class)->handle($check, overrideInput(), $user);

    expect(Wallet::forTeam($team)->balance_cents)->toBe(650);
});

it('override de outro usuário não bloqueia este', function (): void {
    [$team, $user] = setupOverrideTeam();
    $otherUser = User::factory()->create();
    resolve(TenantManager::class)->set($team);
    resolve(ProviderRegistry::class)->register(FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano'])));
    $check = Check::factory()->priced(350)->create(['provider_key' => 'fake']);
    UserCheckOverride::factory()->blocked()->create([
        'team_id' => $team->id,
        'user_id' => $otherUser->id,
        'check_id' => $check->id,
    ]);

    $execution = resolve(ExecutarCheck::class)->handle($check, overrideInput(), $user);

    expect($execution->outcome->value)->toBe('success')
        ->and(Wallet::forTeam($team)->balance_cents)->toBe(650);
});

it('sem usuário nenhum override é aplicado', function (): void {
    [$team, $user] = setupOverrideTeam();
    resolve(ProviderRegistry::class)->register(FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano'])));
    $check = Check::factory()->priced(350)->create(['provider_key' => 'fake']);
    UserCheckOverride::factory()->blocked()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'check_id' => $check->id,
    ]);

    $execution = resolve(ExecutarCheck::class)->handle($check, overrideInput());

    expect($execution->outcome->value)->toBe('success')
        ->and(Wallet::forTeam($team)->balance_cents)->toBe(650);
});

it('override concedido (comp) não consome a franquia compartilhada do time', function (): void {
    [$team, $userA] = setupOverrideTeam();
    PlanSubscription::factory()->active()->create(['team_id' => $team->id, 'plan' => 'pro']);
    resolve(ProviderRegistry::class)->register(FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano'])));
    $check = Check::factory()->create(['provider_key' => 'fake']);
    PlanCheck::factory()->create(['plan' => 'pro', 'check_id' => $check->id, 'quota' => 2]);
    UserCheckOverride::factory()->granted()->create([
        'team_id' => $team->id,
        'user_id' => $userA->id,
        'check_id' => $check->id,
    ]);

    $userB = User::factory()->create();
    $team->members()->attach($userB);
    $userB->assignTeamRole($team, TeamRole::Member);
    resolve(TenantManager::class)->set($team);

    // Usuário A tem grant: roda 3x, mais do que a quota do time (2), grátis.
    resolve(ExecutarCheck::class)->handle($check, overrideInput(), $userA);
    resolve(ExecutarCheck::class)->handle($check, overrideInput(), $userA);
    resolve(ExecutarCheck::class)->handle($check, overrideInput(), $userA);

    $status = resolve(Allowance::class)->for($team, $check);
    expect($status->used)->toBe(0)
        ->and($status->hasRemaining())->toBeTrue();

    // Usuário B, sem override, ainda executa grátis dentro da franquia do plano.
    $executionB = resolve(ExecutarCheck::class)->handle($check, overrideInput(), $userB);

    expect($executionB->outcome->value)->toBe('success')
        ->and($executionB->consumed_franquia)->toBeTrue()
        ->and(resolve(Allowance::class)->for($team, $check)->used)->toBe(1);
});

it('execução sob franquia do plano incrementa o used', function (): void {
    [$team, $user] = setupOverrideTeam();
    PlanSubscription::factory()->active()->create(['team_id' => $team->id, 'plan' => 'pro']);
    resolve(ProviderRegistry::class)->register(FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano'])));
    $check = Check::factory()->create(['provider_key' => 'fake']);
    PlanCheck::factory()->create(['plan' => 'pro', 'check_id' => $check->id, 'quota' => 2]);

    $execution = resolve(ExecutarCheck::class)->handle($check, overrideInput(), $user);

    expect($execution->consumed_franquia)->toBeTrue()
        ->and(resolve(Allowance::class)->for($team, $check)->used)->toBe(1);
});

it('cobrança em crédito após franquia esgotada não consome mais franquia', function (): void {
    [$team, $user] = setupOverrideTeam();
    PlanSubscription::factory()->active()->create(['team_id' => $team->id, 'plan' => 'pro']);
    resolve(ProviderRegistry::class)->register(FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano'])));
    $check = Check::factory()->priced(350)->create(['provider_key' => 'fake']);
    PlanCheck::factory()->create(['plan' => 'pro', 'check_id' => $check->id, 'quota' => 1]);

    // Primeira: dentro da franquia, grátis, used vai a 1 (o teto).
    resolve(ExecutarCheck::class)->handle($check, overrideInput(), $user);
    // Segunda: franquia esgotada, cai para crédito.
    $creditExecution = resolve(ExecutarCheck::class)->handle($check, overrideInput(), $user);

    expect($creditExecution->consumed_franquia)->toBeFalse()
        ->and(Wallet::forTeam($team)->balance_cents)->toBe(650)
        ->and(resolve(Allowance::class)->for($team, $check)->used)->toBe(1);
});
