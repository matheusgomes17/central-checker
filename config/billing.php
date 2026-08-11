<?php

declare(strict_types=1);

use App\Enums\TeamPermission;

return [

    /*
    |--------------------------------------------------------------------------
    | Planos
    |--------------------------------------------------------------------------
    |
    | A chave é o identificador interno e nunca muda; o price_id é o da Stripe e
    | pode trocar entre ambientes ou quando o preço é reajustado. Gravar o
    | identificador interno em vez do price_id é o que permite reajustar sem
    | perder de vista quem está em qual plano.
    |
    | Limite null significa "sem limite". Zero significa zero, e é uma resposta
    | diferente.
    |
    | Só entra aqui limite que tenha contador registrado em ResourceCounters.
    | O boilerplate não tem projeto nem armazenamento, então anunciá-los seria
    | vender teto que ninguém mede — veja o CLAUDE.md para acrescentar o seu.
    |
    | Só entra aqui recurso que alguma parte do produto verifique. SSO e trilha
    | de auditoria saíram por isso: o SSO não existe, e a trilha só aparece no
    | painel do operador. Voltam quando houver o que entregar.
    |
    */

    'plans' => [

        'free' => [
            'name' => 'Free',
            'price_id' => null,
            'price' => 0,
            'limits' => [
                'members' => 3,
            ],
            'features' => [],
            'api_rate_limit' => 60,
        ],

        'pro' => [
            'name' => 'Pro',
            'price_id' => env('STRIPE_PRICE_PRO'),
            'price' => 4900,
            'limits' => [
                'members' => 20,
            ],
            'features' => ['api'],
            'api_rate_limit' => 600,
        ],

        'business' => [
            'name' => 'Business',
            'price_id' => env('STRIPE_PRICE_BUSINESS'),
            'price' => 19900,
            'limits' => [
                'members' => null,
            ],
            'features' => ['api'],
            'api_rate_limit' => 6_000,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Plano de quem não assina
    |--------------------------------------------------------------------------
    |
    | Time sem assinatura ativa cai aqui. Existe para que "sem plano" nunca
    | signifique "sem limite": a ausência de assinatura é um estado do produto,
    | não um vazio.
    |
    */

    'default_plan' => 'free',

    /*
    |--------------------------------------------------------------------------
    | Teste grátis
    |--------------------------------------------------------------------------
    |
    | Dias de teste sem cartão. Zero desliga o teste.
    |
    */

    'trial_days' => (int) env('BILLING_TRIAL_DAYS', 14),

    /*
    |--------------------------------------------------------------------------
    | Permissão exigida para gerir cobrança
    |--------------------------------------------------------------------------
    */

    'manage_permission' => TeamPermission::ManageBilling->value,

    /*
    |--------------------------------------------------------------------------
    | Verificação contra a Stripe de verdade
    |--------------------------------------------------------------------------
    |
    | A suíte é offline por padrão. Ligar isto faz o teste de integração rodar
    | contra a API da Stripe, o que exige chave e price id válidos — e deixa a
    | suíte dependente de rede, que é por que fica desligado.
    |
    */

    'stripe_integration' => (bool) env('STRIPE_INTEGRATION', false),

    'recharge' => [
        // Limites da recarga por Pix, em centavos. A VirtualPay fala reais na
        // fronteira; aqui é centavos como o resto do dinheiro do sistema.
        'min_cents' => (int) env('RECHARGE_MIN_CENTS', 1000),
        'max_cents' => (int) env('RECHARGE_MAX_CENTS', 100000),
        'expiration_seconds' => (int) env('RECHARGE_EXPIRATION_SECONDS', 3600),
    ],

    'virtualpay_integration' => (bool) env('VIRTUALPAY_INTEGRATION', false),

];
