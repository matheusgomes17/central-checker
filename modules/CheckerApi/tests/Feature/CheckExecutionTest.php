<?php

declare(strict_types=1);

use App\Models\Team;
use App\Support\TenantManager;
use Illuminate\Support\Facades\DB;
use Modules\CheckerApi\Models\CheckExecution;

it('grava o resultado cifrado em repouso', function (): void {
    $team = Team::factory()->create();
    resolve(TenantManager::class)->set($team);

    $execution = CheckExecution::factory()->create([
        'team_id' => $team->id,
        'result' => ['nome' => 'Fulano Secreto'],
    ]);

    // Pela relação, o cast decifra.
    expect($execution->fresh()->result)->toBe(['nome' => 'Fulano Secreto']);

    // Cru no banco, o texto não contém o valor.
    $raw = DB::table('check_executions')->where('id', $execution->id)->value('result');
    expect($raw)->not->toContain('Fulano Secreto');
});

it('é escopado por time — um time não vê a execução do outro', function (): void {
    $tenants = resolve(TenantManager::class);

    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();

    $tenants->set($teamA);
    CheckExecution::factory()->create(['team_id' => $teamA->id]);

    $tenants->set($teamB);

    expect(CheckExecution::query()->count())->toBe(0);
});
