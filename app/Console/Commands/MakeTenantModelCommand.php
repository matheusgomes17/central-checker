<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

use function Laravel\Prompts\text;

/**
 * Creates a model that belongs to a team, with its migration and factory.
 *
 * `make:model -mf` produces a model with no tenant and a migration with no
 * team_id, and both mistakes are quiet: the model reads across every team, and
 * the architecture rule only catches it on the next run of the suite. Three
 * files have to agree for a tenant model to be right, so the generator writes
 * all three.
 */
#[Signature('make:tenant-model {name? : Nome do model em StudlyCase — ex.: Project}')]
#[Description('Cria um model escopado por time, com migration e factory')]
class MakeTenantModelCommand extends Command
{
    public function handle(): int
    {
        $name = Str::studly((string) ($this->argument('name') ?? text(
            label: 'Qual o nome do model?',
            placeholder: 'Project',
            required: true,
        )));

        $table = Str::snake(Str::pluralStudly($name));

        $files = [
            app_path("Models/{$name}.php") => $this->model($name),
            database_path("factories/{$name}Factory.php") => $this->factory($name),
            database_path('migrations/'.date('Y_m_d_His')."_create_{$table}_table.php") => $this->migration($table),
        ];

        foreach (array_keys($files) as $path) {
            if (file_exists($path)) {
                $this->components->error('Já existe '.Str::after($path, base_path().'/').'.');

                return self::FAILURE;
            }
        }

        foreach ($files as $path => $contents) {
            if (! is_dir($directory = dirname($path))) {
                mkdir($directory, recursive: true);
            }

            file_put_contents($path, $contents);
            $this->components->info('Criado '.Str::after($path, base_path().'/').'.');
        }

        $this->components->warn('Descreva as colunas na migration e no factory antes de rodar `php artisan migrate`. O model já nasce escopado por time — se ele não deveria ser, ele precisa de justificativa escrita em TenantManager::UNSCOPED_MODELS.');

        return self::SUCCESS;
    }

    private function model(string $name): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace App\Models;

        use App\Concerns\BelongsToTenant;
        use Database\Factories\\{$name}Factory;
        use Illuminate\Database\Eloquent\Factories\HasFactory;
        use Illuminate\Database\Eloquent\Model;

        /**
         * Describe what this model is, and why it belongs to a team.
         *
         * @property int \$id
         * @property int \$team_id
         */
        class {$name} extends Model
        {
            /** @use HasFactory<{$name}Factory> */
            use BelongsToTenant, HasFactory;
        }

        PHP;
    }

    private function factory(string $name): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace Database\Factories;

        use App\Models\Team;
        use App\Models\\{$name};
        use Illuminate\Database\Eloquent\Factories\Factory;

        /**
         * @extends Factory<{$name}>
         */
        class {$name}Factory extends Factory
        {
            protected \$model = {$name}::class;

            /**
             * @return array<string, mixed>
             */
            public function definition(): array
            {
                return [
                    // O time vem explícito para que o factory sirva também aos
                    // testes que montam dois times e comparam o que cada um vê.
                    'team_id' => Team::factory(),
                ];
            }
        }

        PHP;
    }

    private function migration(string $table): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::create('{$table}', function (Blueprint \$table): void {
                    \$table->id();
                    \$table->foreignId('team_id')->constrained()->cascadeOnDelete();
                    \$table->timestamps();

                    // O escopo global consulta por team_id em toda leitura; sem
                    // índice isso vira varredura completa quando a tabela crescer.
                    \$table->index('team_id');
                });
            }

            public function down(): void
            {
                Schema::dropIfExists('{$table}');
            }
        };

        PHP;
    }
}
