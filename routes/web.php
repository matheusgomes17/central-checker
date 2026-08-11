<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\VirtualPayWebhookController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function (): void {
        Route::view('dashboard', 'dashboard')->name('dashboard');
    });

Route::middleware(['auth'])->group(function (): void {
    Route::livewire('invitations/{invitation}/accept', 'pages::teams.accept-invitation')->name('invitations.accept');

    // Iniciar exige super-admin, que a própria ação verifica. Parar não exige
    // nada além de estar impersonando: quem está preso numa sessão de outra
    // pessoa precisa poder sair dela.
    Route::post('impersonate/{user}', [ImpersonationController::class, 'store'])->name('impersonate.start');
    Route::delete('impersonate', [ImpersonationController::class, 'destroy'])->name('impersonate.stop');
});

// O throttle usa o mesmo limiter do login: o callback social é um caminho de
// autenticação como qualquer outro.
Route::middleware(['guest', 'throttle:login'])->group(function (): void {
    Route::get('auth/{provider}/redirect', [SocialAuthController::class, 'redirect'])->name('social.redirect');
    Route::get('auth/{provider}/callback', [SocialAuthController::class, 'callback'])->name('social.callback');
});

require __DIR__.'/settings.php';

/*
|--------------------------------------------------------------------------
| VirtualPay
|--------------------------------------------------------------------------
|
| Mesmo caso do webhook da Stripe acima: a rota continua dentro do grupo web,
| e é a exceção em bootstrap/app.php (PreventRequestForgery::except) que tira
| o CSRF do caminho. Sem sessão nem cookie, quem autentica é a assinatura do
| payload.
|
*/

Route::post('webhooks/virtualpay', VirtualPayWebhookController::class)->name('webhooks.virtualpay');
