<?php

declare(strict_types=1);

use App\Models\Team;
use Modules\Wallet\Models\Wallet;

it('cria a carteira do time uma vez e reusa depois', function (): void {
    $team = Team::factory()->create();

    $first = Wallet::forTeam($team);
    $second = Wallet::forTeam($team);

    expect($first->id)->toBe($second->id)
        ->and($first->team_id)->toBe($team->id)
        ->and($first->balance_cents)->toBe(0)
        ->and(Wallet::withoutTenancy(fn () => Wallet::query()->where('team_id', $team->id)->count()))->toBe(1);
});

it('resolve a carteira sem tenant no contexto', function (): void {
    $team = Team::factory()->create();

    // Nenhum tenant setado — forTeam precisa funcionar mesmo assim (job/admin).
    $wallet = Wallet::forTeam($team);

    expect($wallet->team_id)->toBe($team->id);
});
