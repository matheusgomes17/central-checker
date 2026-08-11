<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Concerns\BelongsToTenant;
use App\Support\TenantManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use SplFileInfo;

/**
 * Cross-checks the database against the tenancy rules.
 *
 * The architecture test guards the code: a model without the trait fails CI.
 * This guards the other direction — a table carrying team_id that no model
 * scopes, or one with no index to make the scope's WHERE cheap. Neither shows
 * up as a failing test. The first is a leak waiting for someone to query the
 * table directly; the second is a full scan on every scoped query once the
 * table grows.
 *
 * Exits non-zero when it finds something, so it can run in CI.
 */
#[Signature('tenant:audit')]
#[Description('Aponta tabelas com team_id sem índice ou sem model escopado')]
class TenantAudit extends Command
{
    public function handle(): int
    {
        $scopedTables = $this->tablesScopedByModels();
        $exemptTables = $this->exemptTables();

        /** @var array<int, array{tabela: string, problema: string}> $findings */
        $findings = [];

        foreach ($this->tablesWithTenantColumn() as $table) {
            // Isenta por decisão registrada no TenantManager. Nem escopo nem
            // índice: se ninguém consulta por tenant nessa tabela, um índice
            // por tenant só custa escrita.
            if (in_array($table, $exemptTables, true)) {
                continue;
            }

            if (! in_array($table, $scopedTables, true)) {
                $findings[] = [
                    'tabela' => $table,
                    'problema' => 'tem '.TenantManager::COLUMN.' mas nenhum model usa BelongsToTenant',
                ];

                // Sem model escopado, cobrar índice é ruído: o problema a
                // resolver primeiro é o escopo.
                continue;
            }

            if (! $this->hasUsableIndex($table)) {
                $findings[] = [
                    'tabela' => $table,
                    'problema' => 'nenhum índice começa por '.TenantManager::COLUMN,
                ];
            }
        }

        if ($findings === []) {
            $this->components->info('Nenhum problema de tenancy encontrado no schema.');

            return self::SUCCESS;
        }

        $this->table(['Tabela', 'Problema'], $findings);
        $this->components->error(count($findings).' problema(s) de tenancy encontrado(s).');

        return self::FAILURE;
    }

    /**
     * @return array<int, string>
     */
    private function tablesWithTenantColumn(): array
    {
        /** @var array<int, array{name: string}> $schemaTables */
        $schemaTables = Schema::getTables();

        $tables = [];

        foreach ($schemaTables as $table) {
            if (Schema::hasColumn($table['name'], TenantManager::COLUMN)) {
                $tables[] = $table['name'];
            }
        }

        return $tables;
    }

    /**
     * An index only helps the scope's WHERE when team_id is its first column.
     *
     * A composite index that starts with something else is invisible to a
     * lookup on team_id alone, so counting it would report a table as fine
     * while every scoped query still scans.
     */
    private function hasUsableIndex(string $table): bool
    {
        /** @var array<int, array{columns: array<int, string>}> $indexes */
        $indexes = Schema::getIndexes($table);

        foreach ($indexes as $index) {
            if (($index['columns'][0] ?? null) === TenantManager::COLUMN) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tables the application deliberately does not scope.
     *
     * @return array<int, string>
     */
    private function exemptTables(): array
    {
        return [
            ...array_map(
                fn (string $model): string => (new $model)->getTable(),
                TenantManager::UNSCOPED_MODELS,
            ),
            ...TenantManager::UNSCOPED_TABLES,
        ];
    }

    /**
     * Tables backed by a model that uses BelongsToTenant.
     *
     * @return array<int, string>
     */
    private function tablesScopedByModels(): array
    {
        $tables = [];

        foreach ($this->modelClasses() as $class) {
            if (! in_array(BelongsToTenant::class, class_uses_recursive($class), true)) {
                continue;
            }

            $tables[] = (new $class)->getTable();
        }

        return $tables;
    }

    /**
     * Every concrete model class under the configured model paths.
     *
     * @return array<int, class-string<Model>>
     */
    private function modelClasses(): array
    {
        /** @var array<int, string> $paths */
        $paths = config('tenancy.model_paths', []);

        $directories = array_filter($paths, File::isDirectory(...));

        $classes = [];

        foreach ($directories as $directory) {
            foreach (File::allFiles($directory) as $file) {
                $class = $this->classFor($file);

                if ($class === null) {
                    continue;
                }

                $reflection = new ReflectionClass($class);

                if ($reflection->isSubclassOf(Model::class) && ! $reflection->isAbstract()) {
                    /** @var class-string<Model> $class */
                    $classes[] = $class;
                }
            }
        }

        return $classes;
    }

    /**
     * The class a file declares, read from the file itself.
     *
     * Deriving it from the path looks simpler and is wrong: PSR-4 maps
     * `Modules\` onto `modules/`, so a name derived from the path
     * comes back in the wrong case and no module model is ever found. The declared namespace is
     * the only thing that survives whatever mapping a project chooses.
     *
     * @return class-string|null
     */
    private function classFor(SplFileInfo $file): ?string
    {
        $contents = File::get($file->getRealPath());

        if (! preg_match('/^namespace\s+([^;]+);/m', $contents, $namespace)) {
            return null;
        }

        if (! preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $contents, $class)) {
            return null;
        }

        $fullyQualified = trim($namespace[1]).'\\'.$class[1];

        return class_exists($fullyQualified) ? $fullyQualified : null;
    }
}
