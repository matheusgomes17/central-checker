<?php

declare(strict_types=1);

namespace Modules\Wallet\Actions;

use App\Support\TenantManager;
use Illuminate\Support\Facades\DB;
use Modules\Wallet\Enums\WalletHoldStatus;
use Modules\Wallet\Exceptions\HoldNotActiveException;
use Modules\Wallet\Models\WalletHold;

/**
 * Releases a hold back to the available balance without moving any money.
 *
 * Idempotent for an already-released hold — the expiry sweep and the caller's
 * failure path can both fire on the same hold, and neither should error. A
 * settled hold cannot be released: the money already moved.
 */
class ReleaseHold
{
    public function __construct(private readonly TenantManager $tenants) {}

    public function handle(WalletHold $hold): void
    {
        $this->tenants->forTenant($hold->team, function () use ($hold): void {
            DB::transaction(function () use ($hold): void {
                $locked = WalletHold::query()->whereKey($hold->getKey())->lockForUpdate()->firstOrFail();

                if ($locked->status === WalletHoldStatus::Released) {
                    return;
                }

                if ($locked->status !== WalletHoldStatus::Active) {
                    throw HoldNotActiveException::forId($locked->id);
                }

                $locked->forceFill(['status' => WalletHoldStatus::Released, 'released_at' => now()])->save();
            });
        });
    }
}
