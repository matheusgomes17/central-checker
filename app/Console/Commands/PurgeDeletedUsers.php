<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

/**
 * Permanently removes accounts whose retention window has closed.
 *
 * Deleting an account is a soft delete: access ends immediately, but the rows
 * survive for a grace period so the decision can be reversed. This command is
 * what actually keeps the promise that the data eventually goes away, so it
 * has to be scheduled — see routes/console.php.
 */
#[Signature('users:purge {--dry-run : Lista o que seria removido sem remover}')]
#[Description('Remove definitivamente contas excluídas além do prazo de retenção')]
class PurgeDeletedUsers extends Command
{
    public function handle(): int
    {
        $days = Config::integer('accounts.purge_after_days');
        $cutoff = now()->subDays($days);

        $query = User::onlyTrashed()->where('deleted_at', '<=', $cutoff);

        $count = $query->count();

        if ($count === 0) {
            $this->components->info("Nenhuma conta excluída há mais de {$days} dias.");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->components->info("{$count} conta(s) seriam removidas definitivamente.");

            return self::SUCCESS;
        }

        // forceDelete em cada model, e não um delete em massa: só assim os
        // eventos do Eloquent disparam e o que estiver pendurado no usuário
        // (tokens, passkeys) desce junto pelas cascatas configuradas.
        $purged = 0;

        $query->each(function (User $user) use (&$purged): void {
            $user->forceDelete();
            $purged++;
        });

        $this->components->info("{$purged} conta(s) removidas definitivamente.");

        return self::SUCCESS;
    }
}
