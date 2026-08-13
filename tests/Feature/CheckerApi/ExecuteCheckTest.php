<?php

declare(strict_types=1);

use App\Actions\CheckerApi\ExecuteCheck;
use App\Models\Team;
use App\Support\TenantManager;
use Modules\CheckerApi\Enums\ProviderOutcome;
use Modules\CheckerApi\Exceptions\CheckUnavailableException;
use Modules\CheckerApi\Models\Check;
use Modules\CheckerApi\Support\CheckInput;
use Modules\CheckerApi\Support\FakeProvider;
use Modules\CheckerApi\Support\ProviderRegistry;
use Modules\CheckerApi\Support\ProviderResult;

function runInput(): CheckInput
{
    return CheckInput::fromArray(['cpf' => '12345678901']);
}

it('grava execução de sucesso com o resultado', function (): void {
    $team = Team::factory()->create();
    resolve(TenantManager::class)->set($team);
    $registry = resolve(ProviderRegistry::class);

    $registry->register(FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano'])));
    $check = Check::factory()->create(['provider_key' => 'fake']);

    $execution = resolve(ExecuteCheck::class)->handle($check, runInput());

    expect($execution->outcome)->toBe(ProviderOutcome::Success)
        ->and($execution->result)->toBe(['nome' => 'Fulano'])
        ->and($execution->failure_reason)->toBeNull()
        ->and($execution->input_masked)->toBe(['cpf' => '123******01'])
        ->and($execution->team_id)->toBe($team->id);
});

it('grava nada encontrado sem resultado', function (): void {
    $team = Team::factory()->create();
    resolve(TenantManager::class)->set($team);
    $registry = resolve(ProviderRegistry::class);

    $registry->register(FakeProvider::returning(ProviderResult::noData()));
    $check = Check::factory()->create(['provider_key' => 'fake']);

    $execution = resolve(ExecuteCheck::class)->handle($check, runInput());

    expect($execution->outcome)->toBe(ProviderOutcome::NoData)
        ->and($execution->result)->toBeNull();
});

it('grava falha com o motivo', function (): void {
    $team = Team::factory()->create();
    resolve(TenantManager::class)->set($team);
    $registry = resolve(ProviderRegistry::class);

    $registry->register(FakeProvider::returning(ProviderResult::failed('timeout')));
    $check = Check::factory()->create(['provider_key' => 'fake']);

    $execution = resolve(ExecuteCheck::class)->handle($check, runInput());

    expect($execution->outcome)->toBe(ProviderOutcome::Failed)
        ->and($execution->failure_reason)->toBe('timeout')
        ->and($execution->result)->toBeNull();
});

it('recusa executar check em manutenção', function (): void {
    $team = Team::factory()->create();
    resolve(TenantManager::class)->set($team);
    $registry = resolve(ProviderRegistry::class);

    $registry->register(FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano'])));
    $check = Check::factory()->inMaintenance()->create(['provider_key' => 'fake']);

    resolve(ExecuteCheck::class)->handle($check, runInput());
})->throws(CheckUnavailableException::class);
