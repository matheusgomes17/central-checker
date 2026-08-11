<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;
use Override;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    /**
     * Register any application services.
     */
    #[Override]
    public function register(): void
    {
        $this->hideSensitiveRequestDetails();

        // Sem o ramo "ou é local" do stub: este provider já só é registrado em
        // local, então aquele ramo tornava o filtro sempre verdadeiro e o
        // resto do trecho, decoração.
        Telescope::filter(fn (IncomingEntry $entry): bool => true);
    }

    /**
     * Prevent sensitive request details from being logged by Telescope.
     */
    protected function hideSensitiveRequestDetails(): void
    {
        // O stub devolve cedo em local, o que deixaria senha e cookie de
        // sessão legíveis no painel. Vale esconder mesmo na máquina de quem
        // desenvolve: é a máquina onde a sessão é a sua.
        Telescope::hideRequestParameters(['_token', 'password', 'password_confirmation', 'code']);

        Telescope::hideRequestHeaders([
            'authorization',
            'cookie',
            'x-csrf-token',
            'x-xsrf-token',
        ]);
    }

    /**
     * Register the Telescope gate.
     *
     * This gate determines who can access Telescope in non-local environments.
     */
    #[Override]
    protected function gate(): void
    {
        // Mesma regra dos outros painéis, e não uma lista de e-mails, que
        // desatualiza. Vale registrar que este gate não é o que protege o
        // Telescope: o Gate::before da aplicação libera tudo para super admin
        // e passa por cima de qualquer definição. O que protege é o provider
        // só ser registrado em local, o pacote ser dependência de
        // desenvolvimento e a migração não criar tabela fora de local.
        Gate::define('viewTelescope', fn (?User $user): bool => $user?->isSuperAdmin() ?? false);
    }
}
