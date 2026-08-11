<?php

declare(strict_types=1);

use App\Console\Commands\PurgeDeletedUsers;
use App\Models\TeamInvitation;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function (): void {
    TeamInvitation::query()
        ->whereNotNull('expires_at')
        ->where('expires_at', '<', now())
        ->delete();
})->daily()->description('Delete expired team invitations');

// Sem isto o soft delete vira retenção eterna, que é exatamente o que a
// exclusão de conta promete não fazer.
// Sem isto o painel de métricas do Horizon fica em branco para sempre: rodar
// o horizon não gera snapshot, quem gera é este comando.
Schedule::command('horizon:snapshot')
    ->everyFiveMinutes()
    ->description('Snapshot das métricas do Horizon');

Schedule::command(PurgeDeletedUsers::class)
    ->daily()
    ->withoutOverlapping()
    ->description('Purge accounts past their retention window');

/*
|--------------------------------------------------------------------------
| Sinal de vida do agendador
|--------------------------------------------------------------------------
|
| Um agendador que parou não emite erro: ele simplesmente não faz nada, e a
| única forma de perceber sem um sinal externo é dar falta do resultado dias
| depois. Este ping é o que permite um serviço de fora avisar antes disso.
|
| Sem URL configurada, nada é agendado — um heartbeat que não vai a lugar
| nenhum só dá a impressão de estar sendo vigiado.
|
*/

$heartbeat = Config::string('app.scheduler_heartbeat_url');

if ($heartbeat !== '') {
    // O name() vem antes do withoutOverlapping(): um evento de callback não
    // tem nome próprio para servir de chave de trava, e o Laravel recusa em
    // vez de inventar uma.
    Schedule::call(fn () => Http::timeout(5)->get($heartbeat))
        ->name('scheduler-heartbeat')
        ->everyFiveMinutes()
        ->withoutOverlapping()
        ->description('Heartbeat do agendador');
}
