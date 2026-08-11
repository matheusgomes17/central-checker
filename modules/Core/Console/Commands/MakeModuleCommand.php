<?php

declare(strict_types=1);

namespace Modules\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

use function Laravel\Prompts\text;

use Modules\Core\Support\Modules;

#[\Illuminate\Console\Attributes\Description('Cria um novo módulo em modules/ e o habilita em config/modules.php')]
#[\Illuminate\Console\Attributes\Signature('make:module {name? : Nome do módulo em StudlyCase}')]
class MakeModuleCommand extends Command
{
    /**
     * Directories every module starts with. PascalCase directories hold PHP
     * classes under the Modules\<Name> namespace; lowercase ones follow the
     * framework's own conventions.
     */
    private const array DIRECTORIES = [
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

    public function handle(Modules $modules): int
    {
        $name = Str::studly((string) ($this->argument('name') ?? text(
            label: 'Qual o nome do módulo?',
            placeholder: 'Billing',
            required: true,
        )));

        $path = $modules->path($name);

        if (is_dir($path)) {
            $this->components->error("O módulo [{$name}] já existe em modules/{$name}.");

            return self::FAILURE;
        }

        foreach (self::DIRECTORIES as $directory) {
            mkdir($path.'/'.$directory, recursive: true);

            if (! str_starts_with($directory, 'routes')) {
                touch($path.'/'.$directory.'/.gitkeep');
            }
        }

        file_put_contents($path.'/Providers/'.$name.'ServiceProvider.php', $this->provider($name));
        file_put_contents($path.'/routes/web.php', $this->routes($name));

        $this->enable($name);

        $this->components->info("Módulo [{$name}] criado em modules/{$name} e habilitado em config/modules.php.");
        $this->components->warn('Rode `composer dump-autoload` se as classes do módulo não forem encontradas.');

        return self::SUCCESS;
    }

    private function provider(string $name): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace Modules\\{$name}\\Providers;

        use Modules\\Core\\Providers\\ModuleServiceProvider;

        class {$name}ServiceProvider extends ModuleServiceProvider
        {
            protected function name(): string
            {
                return '{$name}';
            }
        }

        PHP;
    }

    private function routes(string $name): string
    {
        $slug = Str::kebab($name);

        return <<<PHP
        <?php

        declare(strict_types=1);

        // Rotas web do módulo {$name}. Carregadas automaticamente pelo
        // ModuleServiceProvider com o middleware "web".
        // Views e componentes Livewire do módulo usam o namespace "{$slug}::".

        PHP;
    }

    /**
     * Append the module to the enabled list in config/modules.php.
     */
    private function enable(string $name): void
    {
        $file = config_path('modules.php');
        $contents = (string) file_get_contents($file);

        $updated = preg_replace(
            "/('enabled' => \[)/",
            "$1\n        '{$name}',",
            $contents,
            limit: 1,
        );

        if ($updated === null || $updated === $contents) {
            $this->components->warn("Não consegui editar config/modules.php. Adicione '{$name}' manualmente.");

            return;
        }

        file_put_contents($file, $updated);
    }
}
