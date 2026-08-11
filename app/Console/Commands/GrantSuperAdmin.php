<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SystemRole;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Grants or revokes platform administration from the command line.
 *
 * There is deliberately no way to do this from the interface. Super admin
 * passes every authorization check in the application, so the only person who
 * should be able to hand it out is someone with access to the server.
 */
#[Signature('user:super-admin {email : E-mail do usuário} {--revoke : Revoga em vez de conceder}')]
#[Description('Concede ou revoga o papel de super-admin')]
class GrantSuperAdmin extends Command
{
    public function handle(): int
    {
        $email = (string) $this->argument('email');

        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User) {
            $this->components->error("Nenhum usuário com o e-mail [{$email}].");

            return self::FAILURE;
        }

        $granting = ! $this->option('revoke');

        $user->setSystemRole(SystemRole::SuperAdmin, $granting);

        $this->components->info($granting
            ? "[{$email}] agora é super-admin."
            : "[{$email}] não é mais super-admin.");

        return self::SUCCESS;
    }
}
