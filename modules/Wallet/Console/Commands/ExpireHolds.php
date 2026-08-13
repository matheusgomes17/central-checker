<?php

declare(strict_types=1);

namespace Modules\Wallet\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Modules\Wallet\Enums\WalletHoldStatus;
use Modules\Wallet\Models\WalletHold;

/**
 * Releases holds that were never settled or released in time.
 *
 * A hold outlives its window only when the process that made it died between
 * reserving and settling. Sweeping them frees the reserved balance that would
 * otherwise sit locked forever. Runs across every team.
 */
#[Signature('wallet:expire-holds')]
#[Description('Solta holds ativos que passaram do prazo, em todos os times')]
class ExpireHolds extends Command
{
    public function handle(): int
    {
        $released = WalletHold::acrossTenants()
            ->where('status', WalletHoldStatus::Active->value)
            ->where('expires_at', '<', now())
            ->update([
                'status' => WalletHoldStatus::Released->value,
                'released_at' => now(),
            ]);

        $this->components->info("Holds soltos: {$released}.");

        return self::SUCCESS;
    }
}
