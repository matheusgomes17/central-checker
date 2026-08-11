<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves the team role from the membership pivot into the permission tables.
 *
 * Leaving the column in place would give the application two answers to "what
 * is this person's role here", and the day they disagree is a silent
 * authorization bug. So the data moves and the column goes.
 *
 * Written with the query builder, not models: a migration has to keep working
 * after the models it once matched have moved on.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->ensureRolesExist();

        $roleIds = DB::table('roles')->whereNull('team_id')->pluck('id', 'name');

        DB::table('team_members')->orderBy('id')->chunkById(200, function ($memberships) use ($roleIds): void {
            $rows = [];

            foreach ($memberships as $membership) {
                $roleId = $roleIds[$membership->role] ?? null;

                if ($roleId === null) {
                    continue;
                }

                $rows[] = [
                    'role_id' => $roleId,
                    'model_type' => (new User)->getMorphClass(),
                    'model_id' => $membership->user_id,
                    'team_id' => $membership->team_id,
                ];
            }

            if ($rows !== []) {
                DB::table('model_has_roles')->insertOrIgnore($rows);
            }
        });

        Schema::table('team_members', function (Blueprint $table): void {
            $table->dropColumn('role');
        });
    }

    public function down(): void
    {
        Schema::table('team_members', function (Blueprint $table): void {
            $table->string('role')->default(TeamRole::Member->value);
        });

        $roleNames = DB::table('roles')->whereNull('team_id')->pluck('name', 'id');

        DB::table('model_has_roles')
            ->where('model_type', (new User)->getMorphClass())
            ->whereNotNull('team_id')
            ->orderBy('model_id')
            ->each(function ($assignment) use ($roleNames): void {
                $name = $roleNames[$assignment->role_id] ?? null;

                if ($name === null) {
                    return;
                }

                DB::table('team_members')
                    ->where('team_id', $assignment->team_id)
                    ->where('user_id', $assignment->model_id)
                    ->update(['role' => $name]);
            });
    }

    /**
     * The backfill needs the roles to exist, and a migration cannot depend on
     * a seeder having run first.
     */
    private function ensureRolesExist(): void
    {
        foreach (TeamRole::cases() as $case) {
            DB::table('roles')->insertOrIgnore([
                'name' => $case->value,
                'guard_name' => 'web',
                'team_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
