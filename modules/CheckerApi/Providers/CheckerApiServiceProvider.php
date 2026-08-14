<?php

declare(strict_types=1);

namespace Modules\CheckerApi\Providers;

use Modules\CheckerApi\Support\FakeProvider;
use Modules\CheckerApi\Support\ProviderRegistry;
use Modules\CheckerApi\Support\ProviderResult;
use Modules\Core\Providers\ModuleServiceProvider;
use Override;

class CheckerApiServiceProvider extends ModuleServiceProvider
{
    protected function name(): string
    {
        return 'CheckerApi';
    }

    #[Override]
    protected function registerModule(): void
    {
        // Singleton para as registrações de provider sobreviverem à requisição.
        // Providers reais entram aqui nas fases seguintes; em Fase A começa vazio.
        $this->app->singleton(ProviderRegistry::class);

        // Só em local: o FakeProvider existe para "tests and local demos", mas
        // até aqui só os testes o registravam — qualquer execução local morria
        // em ProviderNotRegisteredException assim que passava da cobrança. Um
        // no_data no meio das respostas prontas deixa o demo mostrar os dois
        // desfechos normais. Em testing cada teste registra o seu próprio fake
        // (este registro não roda lá), e providers reais não passam por aqui.
        if ($this->app->environment('local')) {
            $this->app->afterResolving(ProviderRegistry::class, static function (ProviderRegistry $registry): void {
                if ($registry->has('fake')) {
                    return;
                }

                $registry->register(FakeProvider::returning(
                    ProviderResult::success([
                        'nome' => 'Maria Aparecida da Silva',
                        'situacao_cadastral' => 'Regular',
                        'data_de_inscricao' => '12/03/2004',
                        'unidade_federativa' => 'SP',
                    ]),
                    ProviderResult::noData(),
                    ProviderResult::success([
                        'nome' => 'João Pereira dos Santos',
                        'situacao_cadastral' => 'Regular',
                        'data_de_inscricao' => '28/09/1998',
                        'unidade_federativa' => 'MG',
                    ]),
                ));
            });
        }
    }
}
