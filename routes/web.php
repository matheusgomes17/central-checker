<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\VirtualPayWebhookController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Laravel\Cashier\Http\Controllers\PaymentController;

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
| Cobrança
|--------------------------------------------------------------------------
|
| Registradas aqui porque o controller de webhook é estendido — com as rotas
| do próprio Cashier ligadas, o mesmo caminho teria duas.
|
| O webhook fica fora do grupo web de propósito: ele não tem sessão nem
| cookie, e o CSRF do grupo recusaria a chamada da Stripe. Quem autentica é a
| assinatura do payload.
|
*/

Route::post(Config::string('cashier.path').'/webhook', [StripeWebhookController::class, 'handleWebhook'])
    ->name('cashier.webhook');

Route::get(Config::string('cashier.path').'/payment/{id}', [PaymentController::class, 'show'])
    ->middleware(['web', 'auth'])
    ->name('cashier.payment');

/*
|--------------------------------------------------------------------------
| VirtualPay
|--------------------------------------------------------------------------
|
| Mesmo motivo do webhook da Stripe acima: fora do grupo web porque não tem
| sessão nem cookie, e quem autentica é a assinatura do payload, não o CSRF.
|
*/

Route::post('webhooks/virtualpay', VirtualPayWebhookController::class)->name('webhooks.virtualpay');
