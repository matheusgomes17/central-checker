<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\MemberController;
use App\Http\Controllers\Api\V1\TeamController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Tudo aqui exige um token, e o token é quem diz de que time a requisição
| fala — o middleware da API define o tenant a partir dele antes de qualquer
| consulta.
|
| A habilidade do token é teto, não permissão: `ability:write` diz o que a
| credencial pode tentar, e a policy continua dizendo o que a pessoa pode
| fazer. As duas checagens rodam.
|
| O throttle é por plano, para que o limite acompanhe o que o time paga em vez
| de ser o mesmo número para todo mundo.
|
*/

Route::middleware(['auth:sanctum', 'throttle:api', 'plan-feature:api'])->group(function (): void {
    Route::get('user', fn (Request $request) => $request->user()?->only(['id', 'name', 'email']))
        ->name('api.user');

    Route::middleware('ability:read')->group(function (): void {
        Route::get('team', [TeamController::class, 'show'])->name('api.team.show');
        Route::get('members', [MemberController::class, 'index'])->name('api.members.index');
        Route::get('members/{member}', [MemberController::class, 'show'])->name('api.members.show');
        Route::get('invitations', [InvitationController::class, 'index'])->name('api.invitations.index');
    });

    Route::middleware('ability:write')->group(function (): void {
        Route::delete('members/{member}', [MemberController::class, 'destroy'])->name('api.members.destroy');
        Route::post('invitations', [InvitationController::class, 'store'])->name('api.invitations.store');
        // {invitation:id}, e não a ligação padrão: o model se resolve por
        // `code`, e o code é a credencial de entrada no time. Cancelar pela
        // rota padrão exigiria mandá-lo na URL, onde ele fica no log de
        // acesso, no proxy e no histórico.
        Route::delete('invitations/{invitation:id}', [InvitationController::class, 'destroy'])->name('api.invitations.destroy');
    });
});
