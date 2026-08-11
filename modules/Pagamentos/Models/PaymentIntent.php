<?php

declare(strict_types=1);

namespace Modules\Pagamentos\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Pagamentos\Database\Factories\PaymentIntentFactory;
use Modules\Pagamentos\Enums\PaymentPurpose;
use Modules\Pagamentos\Enums\PaymentStatus;
use Override;

/**
 * A Pix charge in flight, linking the gateway's reference/external_id to a
 * team — because the webhook that reports payment arrives with no tenant in
 * context, and this is what lets it be resolved back to one.
 *
 * Scoped by team like every domain model. Kept intentionally free of state
 * transitions: marking a charge paid is an Action, not a model method.
 *
 * @property int $id
 * @property int $team_id
 * @property string $reference
 * @property string|null $external_id
 * @property int $amount_cents
 * @property PaymentStatus $status
 * @property string $method
 * @property PaymentPurpose $purpose
 * @property string|null $plan
 * @property string|null $copy_paste
 * @property string|null $qr_code
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $paid_at
 */
#[Fillable([
    'reference',
    'external_id',
    'amount_cents',
    'status',
    'method',
    'purpose',
    'plan',
    'copy_paste',
    'qr_code',
    'expires_at',
    'paid_at',
])]
class PaymentIntent extends Model
{
    /** @use HasFactory<PaymentIntentFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'purpose' => PaymentPurpose::class,
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }
}
