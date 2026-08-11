<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
| Os curingas do canal chegam como string, sempre. Com strict_types ligado,
| declarar o parâmetro como int faz o PHP recusar a chamada, o callback estoura
| e a autorização vira 403 — para todo mundo, inclusive quem tinha direito.
*/

/*
|--------------------------------------------------------------------------
| Canais de broadcast
|--------------------------------------------------------------------------
|
| Autorização de canal é fronteira de tenant. Um canal de time que aceite
| qualquer autenticado entrega em tempo real o que o escopo global passou a
| fase inteira impedindo de sair por consulta — e sem deixar rastro numa
| query, porque não há query nenhuma.
|
*/

/**
 * Notificações pessoais. O nome do canal é o que o Laravel usa por padrão
 * para o canal privado de um notifiable.
 */
Broadcast::channel('App.Models.User.{id}', fn (User $user, string $id): bool => $user->id === (int) $id);

/**
 * Canal do time. Só quem é membro entra.
 */
Broadcast::channel('teams.{teamId}', function (User $user, string $teamId): bool {
    $team = Team::query()->whereKey($teamId)->first();

    return $team instanceof Team && $user->belongsToTeam($team);
});

/**
 * Canal de presença do time: além de autorizar, devolve quem é a pessoa para
 * os demais membros verem.
 *
 * @return array{id: int, name: string}|false
 */
Broadcast::channel('teams.{teamId}.presence', function (User $user, string $teamId): array|false {
    $team = Team::query()->whereKey($teamId)->first();

    if (! $team instanceof Team || ! $user->belongsToTeam($team)) {
        return false;
    }

    // Devolver array em vez de booleano é o que faz deste um canal de
    // presença: o valor vira o que os demais membros veem de quem entrou.
    return ['id' => $user->id, 'name' => $user->name];
});
