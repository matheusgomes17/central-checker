<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

use function Laravel\Prompts\text;

/**
 * Creates an action class under app/Actions.
 *
 * Actions are where the application's verbs live: one class, one `handle`, no
 * second public method. `make:class` would produce an empty class in the wrong
 * place, and the shape of the file is the part worth not retyping.
 */
#[Signature('make:action {name? : Nome da action, com pasta — ex.: Teams/ArchiveTeam}')]
#[Description('Cria uma action em app/Actions')]
class MakeActionCommand extends Command
{
    public function handle(): int
    {
        $input = (string) ($this->argument('name') ?? text(
            label: 'Qual o nome da action?',
            placeholder: 'Teams/ArchiveTeam',
            required: true,
        ));

        $segments = array_map(
            Str::studly(...),
            array_filter(preg_split('#[/\\\\]#', $input) ?: []),
        );

        $class = (string) array_pop($segments);
        $namespace = implode('\\', ['App', 'Actions', ...$segments]);
        $path = app_path('Actions/'.implode('/', [...$segments, $class.'.php']));

        if (file_exists($path)) {
            $this->components->error("A action [{$class}] já existe em ".Str::after($path, base_path().'/').'.');

            return self::FAILURE;
        }

        if (! is_dir($directory = dirname($path))) {
            mkdir($directory, recursive: true);
        }

        file_put_contents($path, $this->stub($namespace, $class));

        $this->components->info('Action criada em '.Str::after($path, base_path().'/').'.');

        return self::SUCCESS;
    }

    private function stub(string $namespace, string $class): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        class {$class}
        {
            /**
             * Describe what this action does, in one sentence.
             */
            public function handle(): void
            {
                //
            }
        }

        PHP;
    }
}
