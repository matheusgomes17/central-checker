<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\View\FileViewFinder;
use Modules\Core\Providers\ModuleServiceProvider;
use Modules\Core\Support\Modules;

/*
|--------------------------------------------------------------------------
| Sistema de módulos
|--------------------------------------------------------------------------
|
| Estes testes não dependem de nenhum módulo de domínio existir: o boilerplate
| é entregue sem nenhum. A fiação é exercitada por um provider de mentira que
| aponta para tests/Fixtures/module, e o gerador roda contra um base path
| temporário para não escrever no projeto de verdade.
|
*/

/**
 * Diretórios que MakeModuleCommand cria. Duplicados de propósito: se a
 * constante do comando mudar, o teste precisa falhar em vez de acompanhar.
 */
const MODULE_DIRECTORIES = [
    'Actions',
    'Enums',
    'Models',
    'Policies',
    'Providers',
    'database/factories',
    'database/migrations',
    'resources/views',
    'routes',
    'tests/Feature',
];

/**
 * Provider apontando para o módulo de mentira em tests/Fixtures/module.
 */
function fixtureModuleProvider(Application $app): ModuleServiceProvider
{
    return new class($app) extends ModuleServiceProvider
    {
        protected function name(): string
        {
            return 'Example';
        }

        protected function path(string $relative = ''): string
        {
            $base = dirname(__DIR__, 4).'/tests/Fixtures/module';

            return $relative === '' ? $base : $base.'/'.ltrim($relative, '/');
        }
    };
}

function deleteDirectory(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
        $child = $path.'/'.$entry;

        is_dir($child) ? deleteDirectory($child) : unlink($child);
    }

    rmdir($path);
}

it('maps enabled module names to their service provider classes', function (): void {
    config()->set('modules.enabled', ['Alpha', 'Beta']);

    $modules = new Modules;

    expect($modules->providers())->toBe([
        'Modules\Alpha\Providers\AlphaServiceProvider',
        'Modules\Beta\Providers\BetaServiceProvider',
    ]);
});

it('reports whether a given module is enabled', function (): void {
    config()->set('modules.enabled', ['Alpha']);

    $modules = new Modules;

    expect($modules->isEnabled('Alpha'))->toBeTrue()
        ->and($modules->isEnabled('alpha'))->toBeFalse()
        ->and($modules->isEnabled('DoesNotExist'))->toBeFalse();
});

it('resolves paths inside a module directory', function (): void {
    $modules = new Modules;

    expect($modules->path())->toBe(base_path('modules'))
        ->and($modules->path('Core'))->toBe(base_path('modules/Core'))
        ->and($modules->path('Core', 'Support/Modules.php'))->toBe(base_path('modules/Core/Support/Modules.php'));
});

it('registers a provider for every enabled module', function (): void {
    $modules = resolve(Modules::class);

    foreach ($modules->providers() as $provider) {
        expect(class_exists($provider))->toBeTrue("O provider {$provider} não existe.")
            ->and(app()->getLoadedProviders())->toHaveKey($provider);
    }
})->skip(fn (): bool => resolve(Modules::class)->enabled() === [], 'Nenhum módulo habilitado.');

it('exposes the module view directory under its own namespace', function (): void {
    fixtureModuleProvider(app())->boot();

    $finder = View::getFinder();

    expect($finder)->toBeInstanceOf(FileViewFinder::class);

    /** @var FileViewFinder $finder */
    $hints = $finder->getHints();

    expect($hints)->toHaveKey('example')
        ->and($hints['example'])->toContain(dirname(__DIR__, 4).'/tests/Fixtures/module/resources/views')
        ->and($finder->find('example::page'))
        ->toBe(dirname(__DIR__, 4).'/tests/Fixtures/module/resources/views/page.blade.php');
});

it('loads the module routes and migrations', function (): void {
    fixtureModuleProvider(app())->boot();

    // Nome fluente (->name()) entra na rota depois que ela já foi registrada na
    // coleção, então o índice de nomes precisa ser refeito. Num boot normal o
    // RouteServiceProvider faz isso num callback booted(), que aqui já rodou:
    // o teste boota o provider do módulo à mão, fora do ciclo.
    Route::getRoutes()->refreshNameLookups();

    $route = Route::getRoutes()->getByName('example.fixture');

    expect($route)->not->toBeNull()
        ->and($route?->uri())->toBe('example-fixture')
        ->and($route?->gatherMiddleware())->toContain('web')
        ->and(resolve('migrator')->paths())->toContain(dirname(__DIR__, 4).'/tests/Fixtures/module/database/migrations');
});

it('generates a module and enables it', function (): void {
    $original = base_path();
    $temporary = sys_get_temp_dir().'/modules-test-'.bin2hex(random_bytes(6));

    mkdir($temporary.'/config', recursive: true);
    file_put_contents(
        $temporary.'/config/modules.php',
        "<?php\n\nreturn [\n\n    'enabled' => [\n        //\n    ],\n\n];\n",
    );

    app()->setBasePath($temporary);

    try {
        expect(Artisan::call('make:module', ['name' => 'example-domain']))->toBe(Command::SUCCESS);

        $module = $temporary.'/modules/ExampleDomain';

        expect(config_path('modules.php'))->toBe($temporary.'/config/modules.php');

        foreach (MODULE_DIRECTORIES as $directory) {
            expect(is_dir($module.'/'.$directory))->toBeTrue("Faltou o diretório {$directory}.");
        }

        expect((string) file_get_contents($module.'/Providers/ExampleDomainServiceProvider.php'))
            ->toContain('namespace Modules\ExampleDomain\Providers;')
            ->toContain('class ExampleDomainServiceProvider extends ModuleServiceProvider')
            ->toContain("return 'ExampleDomain';")
            ->and((string) file_get_contents($module.'/routes/web.php'))
            ->toContain('example-domain::')
            ->and((string) file_get_contents($temporary.'/config/modules.php'))
            ->toContain("'ExampleDomain',");
    } finally {
        app()->setBasePath($original);
        deleteDirectory($temporary);
    }
});

it('refuses to overwrite an existing module', function (): void {
    $original = base_path();
    $temporary = sys_get_temp_dir().'/modules-test-'.bin2hex(random_bytes(6));

    mkdir($temporary.'/modules/Existing', recursive: true);
    mkdir($temporary.'/config', recursive: true);
    file_put_contents($temporary.'/config/modules.php', "<?php\n\nreturn [\n\n    'enabled' => [],\n\n];\n");

    app()->setBasePath($temporary);

    try {
        expect(Artisan::call('make:module', ['name' => 'Existing']))->toBe(Command::FAILURE);
    } finally {
        app()->setBasePath($original);
        deleteDirectory($temporary);
    }
});
