<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The generators exist to shorten the right path, so what is worth asserting
 * is not that a file appeared but that it appeared already scoped to a team. A
 * `make:tenant-model` that writes a model without the trait is worse than no
 * generator at all, because it looks like it did the work.
 *
 * Each test writes into a temporary base path. A generator tested against the
 * real project leaves its output in the real project.
 */
class GeneratorsTest extends TestCase
{
    private string $temporary;

    private string $original;

    protected function setUp(): void
    {
        parent::setUp();

        $this->original = base_path();
        $this->temporary = sys_get_temp_dir().'/generators-test-'.bin2hex(random_bytes(6));

        mkdir($this->temporary, recursive: true);

        $this->app->setBasePath($this->temporary);
    }

    protected function tearDown(): void
    {
        $this->app->setBasePath($this->original);

        File::deleteDirectory($this->temporary);

        parent::tearDown();
    }

    public function test_it_generates_an_action_inside_a_folder(): void
    {
        $this->assertSame(Command::SUCCESS, Artisan::call('make:action', ['name' => 'teams/archive-team']));

        $contents = (string) file_get_contents($this->temporary.'/app/Actions/Teams/ArchiveTeam.php');

        $this->assertStringContainsString('namespace App\Actions\Teams;', $contents);
        $this->assertStringContainsString('class ArchiveTeam', $contents);
        $this->assertStringContainsString('public function handle(): void', $contents);
        $this->assertStringContainsString('declare(strict_types=1);', $contents);
    }

    public function test_it_refuses_to_overwrite_an_action(): void
    {
        Artisan::call('make:action', ['name' => 'Teams/ArchiveTeam']);

        $this->assertSame(Command::FAILURE, Artisan::call('make:action', ['name' => 'Teams/ArchiveTeam']));
    }

    public function test_the_generated_model_is_scoped_by_team(): void
    {
        $this->assertSame(Command::SUCCESS, Artisan::call('make:tenant-model', ['name' => 'project']));

        $model = (string) file_get_contents($this->temporary.'/app/Models/Project.php');

        $this->assertStringContainsString('use App\Concerns\BelongsToTenant;', $model);
        $this->assertStringContainsString('use BelongsToTenant, HasFactory;', $model);
    }

    public function test_the_generated_migration_carries_the_team_column_and_its_index(): void
    {
        Artisan::call('make:tenant-model', ['name' => 'Project']);

        $migration = (string) file_get_contents($this->migrationPath('projects'));

        $this->assertStringContainsString("Schema::create('projects'", $migration);
        $this->assertStringContainsString("foreignId('team_id')->constrained()->cascadeOnDelete()", $migration);
        $this->assertStringContainsString("index('team_id')", $migration);
    }

    public function test_the_generated_factory_sets_the_team(): void
    {
        Artisan::call('make:tenant-model', ['name' => 'Project']);

        $factory = (string) file_get_contents($this->temporary.'/database/factories/ProjectFactory.php');

        $this->assertStringContainsString('class ProjectFactory extends Factory', $factory);
        $this->assertStringContainsString("'team_id' => Team::factory()", $factory);
    }

    public function test_it_refuses_to_overwrite_a_model(): void
    {
        Artisan::call('make:tenant-model', ['name' => 'Project']);

        $this->assertSame(Command::FAILURE, Artisan::call('make:tenant-model', ['name' => 'Project']));
    }

    private function migrationPath(string $table): string
    {
        $matches = glob($this->temporary."/database/migrations/*_create_{$table}_table.php") ?: [];

        $this->assertCount(1, $matches, "Nenhuma migration gerada para a tabela {$table}.");

        return $matches[0];
    }
}
