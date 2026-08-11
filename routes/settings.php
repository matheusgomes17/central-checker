<?php

declare(strict_types=1);

use App\Http\Controllers\BillingController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::middleware(['auth'])->group(function (): void {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', 'pages::settings.profile')->name('profile.edit');
});

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::livewire('settings/appearance', 'pages::settings.appearance')->name('appearance.edit');

    Route::livewire('settings/security', 'pages::settings.security')
        ->middleware(
            when(
                Features::canManageTwoFactorAuthentication()
                && Features::optionEnabled(Features::twoFactorAuthentication(), 'confirmPassword'),
                ['password.confirm'],
                [],
            ),
        )
        ->name('security.edit');

    Route::livewire('settings/teams', 'pages::teams.index')->name('teams.index');

    Route::livewire('settings/notifications', 'pages::settings.notifications')->name('notifications.edit');
    Route::livewire('settings/api-tokens', 'pages::settings.api-tokens')->name('api-tokens.edit');
    Route::livewire('settings/webhooks', 'pages::settings.webhooks')->name('webhooks.edit');

    Route::livewire('settings/billing', 'pages::settings.billing')->name('billing.edit');
    Route::get('settings/billing/checkout/{plan}', [BillingController::class, 'checkout'])->name('billing.checkout');
    Route::get('settings/billing/portal', [BillingController::class, 'portal'])->name('billing.portal');
    Route::get('settings/billing/invoice/{invoice}', [BillingController::class, 'invoice'])->name('billing.invoice');

    Route::middleware(EnsureTeamMembership::class)->group(function (): void {
        Route::livewire('settings/teams/{team}', 'pages::teams.edit')->name('teams.edit');
    });
});

Route::get('.well-known/passkey-endpoints', fn () => response()->json([
    'enroll' => route('security.edit'),
    'manage' => route('security.edit'),
]))->name('well-known.passkeys');
