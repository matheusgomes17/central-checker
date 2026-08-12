<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Regras de arquitetura
|--------------------------------------------------------------------------
|
| Estas regras rodam no CI e são o que impede o boilerplate de degradar com o
| tempo. Elas valem para app/ e modules/ — se uma regra precisar de exceção,
| documente o porquê no ->ignoring() em vez de removê-la.
|
*/

arch()->preset()->php();
arch()->preset()->security();

arch('todo arquivo declara strict types')
    ->expect(['App', 'Modules'])
    ->toUseStrictTypes();

arch('nada de código de depuração')
    ->expect(['dd', 'ddd', 'dump', 'var_dump', 'print_r', 'ray', 'die', 'exit'])
    ->not->toBeUsed();

arch('env() só é lido a partir dos arquivos de configuração')
    ->expect('env')
    ->not->toBeUsed();

arch('controllers não acessam o banco diretamente')
    ->expect('App\Http\Controllers')
    ->not->toUse([Illuminate\Support\Facades\DB::class, Illuminate\Database\Query\Builder::class]);

/*
|--------------------------------------------------------------------------
| Fronteiras entre módulos
|--------------------------------------------------------------------------
|
| Um módulo pode depender de Core e do próprio código. Não pode depender de
| outro módulo: a comunicação entre domínios acontece por eventos ou pela
| camada de aplicação (app/), que é o ponto de composição.
|
| Core é a base de todos e por isso não pode depender de ninguém.
|
*/

// Str::studly (não strtolower+ucfirst) porque um nome de módulo pode ter mais
// de uma palavra maiúscula (ex.: CheckerApi) — ucfirst(strtolower()) achataria
// isso para "Checkerapi" e o namespace calculado abaixo nunca bateria com o
// diretório de verdade, calando as regras desta seção em silêncio.
$modules = array_map(
    fn (string $directory): string => Illuminate\Support\Str::studly(basename($directory)),
    glob(dirname(__DIR__, 2).'/modules/*', GLOB_ONLYDIR) ?: [],
);

foreach ($modules as $module) {
    $siblings = array_values(array_filter(
        $modules,
        fn (string $other): bool => $other !== $module && ($module === 'Core' || $other !== 'Core'),
    ));

    if ($siblings === []) {
        continue;
    }

    arch("o módulo {$module} não depende de módulos irmãos")
        ->expect("Modules\\{$module}")
        ->not->toUse(array_map(fn (string $other): string => "Modules\\{$other}", $siblings));
}

// Só declaramos os namespaces que existem de fato: apontar para um diretório
// ausente faz o Pest estourar em vez de reprovar a regra.
$enumNamespaces = array_values(array_filter(
    ['App\Enums', ...array_map(fn (string $module): string => "Modules\\{$module}\\Enums", $modules)],
    fn (string $namespace): bool => is_dir(
        dirname(__DIR__, 2).'/'.str_replace(['App\\', 'Modules\\', '\\'], ['app/', 'modules/', '/'], $namespace)
    ),
));

arch('enums não carregam estado')
    ->expect($enumNamespaces)
    ->toBeEnums();

// O Pest mede linhas por arquivo, não por método. Como model é classe de uma
// responsabilidade só, o tamanho do arquivo é o proxy honesto: passar de 150
// linhas significa que regra de negócio vazou para dentro do model e deveria
// estar numa Action.
$modelNamespaces = array_values(array_filter(
    ['App\Models', ...array_map(fn (string $module): string => "Modules\\{$module}\\Models", $modules)],
    fn (string $namespace): bool => is_dir(
        dirname(__DIR__, 2).'/'.str_replace(['App\\', 'Modules\\', '\\'], ['app/', 'modules/', '/'], $namespace)
    ),
));

arch('models não concentram regra de negócio')
    ->expect($modelNamespaces)
    ->toHaveLineCountLessThan(150);

/*
|--------------------------------------------------------------------------
| Escopo de tenant
|--------------------------------------------------------------------------
|
| Vazamento entre tenants é falha crítica de segurança, e a defesa não pode
| depender de alguém lembrar de usar a trait. Todo model novo é escopado por
| time até aparecer na allowlist do TenantManager, com justificativa escrita.
|
| A lista mora lá, e não aqui, porque o `tenant:audit` precisa da mesma: duas
| listas separadas divergiriam, e a que divergisse em silêncio seria a que
| protege.
|
*/

$unscopedModels = [
    ...App\Support\TenantManager::UNSCOPED_MODELS,
    // Não é model: o make:scope do Laravel gera scopes dentro de app/Models.
    'App\Models\Scopes',
];

arch('models de domínio são escopados por tenant')
    ->expect($modelNamespaces)
    ->toUseTrait(App\Concerns\BelongsToTenant::class)
    ->ignoring($unscopedModels);
