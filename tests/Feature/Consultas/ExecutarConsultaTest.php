<?php

declare(strict_types=1);

use App\Actions\Consultas\ExecutarConsulta;
use App\Models\Team;
use App\Support\TenantManager;
use Modules\Consultas\Enums\ProviderOutcome;
use Modules\Consultas\Exceptions\ConsultaUnavailableException;
use Modules\Consultas\Models\Consulta;
use Modules\Consultas\Support\ConsultaInput;
use Modules\Consultas\Support\FakeProvider;
use Modules\Consultas\Support\ProviderRegistry;
use Modules\Consultas\Support\ProviderResult;

beforeEach(function (): void {
    $this->team = Team::factory()->create();
    app(TenantManager::class)->set($this->team);
    $this->registry = app(ProviderRegistry::class);
});

function runInput(): ConsultaInput
{
    return ConsultaInput::fromArray(['cpf' => '12345678901']);
}

it('grava execução de sucesso com o resultado', function (): void {
    $this->registry->register(FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano'])));
    $consulta = Consulta::factory()->create(['provider_key' => 'fake']);

    $execution = app(ExecutarConsulta::class)->handle($consulta, runInput());

    expect($execution->outcome)->toBe(ProviderOutcome::Success)
        ->and($execution->result)->toBe(['nome' => 'Fulano'])
        ->and($execution->failure_reason)->toBeNull()
        ->and($execution->input_masked)->toBe(['cpf' => '123******01'])
        ->and($execution->team_id)->toBe($this->team->id);
});

it('grava nada encontrado sem resultado', function (): void {
    $this->registry->register(FakeProvider::returning(ProviderResult::noData()));
    $consulta = Consulta::factory()->create(['provider_key' => 'fake']);

    $execution = app(ExecutarConsulta::class)->handle($consulta, runInput());

    expect($execution->outcome)->toBe(ProviderOutcome::NoData)
        ->and($execution->result)->toBeNull();
});

it('grava falha com o motivo', function (): void {
    $this->registry->register(FakeProvider::returning(ProviderResult::failed('timeout')));
    $consulta = Consulta::factory()->create(['provider_key' => 'fake']);

    $execution = app(ExecutarConsulta::class)->handle($consulta, runInput());

    expect($execution->outcome)->toBe(ProviderOutcome::Failed)
        ->and($execution->failure_reason)->toBe('timeout')
        ->and($execution->result)->toBeNull();
});

it('recusa executar consulta em manutenção', function (): void {
    $this->registry->register(FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano'])));
    $consulta = Consulta::factory()->inMaintenance()->create(['provider_key' => 'fake']);

    app(ExecutarConsulta::class)->handle($consulta, runInput());
})->throws(ConsultaUnavailableException::class);
