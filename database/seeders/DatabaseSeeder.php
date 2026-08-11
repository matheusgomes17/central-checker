<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Without `WithoutModelEvents`, and not by oversight.
 *
 * Model events carry correctness here rather than convenience: the team slug,
 * the invitation code and the tenant stamp are all born in `creating`. Seeding
 * with events switched off writes an invitation with no code and trips the
 * not-null constraint — or worse, writes a row with no tenant, which is
 * invisible to everyone afterwards.
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Papéis e permissões vêm antes de qualquer usuário: atribuir papel a
        // quem não tem papel para atribuir falha.
        $this->call(RolesAndPermissionsSeeder::class);

        // Os papéis são estrutura e valem em qualquer ambiente; os dados de
        // demonstração são conveniência de quem desenvolve. Em produção este
        // seeder roda para criar os papéis, e só isso.
        if (! app()->isProduction()) {
            $this->call(DemoSeeder::class);
        }
    }
}
