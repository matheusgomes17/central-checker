<?php

declare(strict_types=1);

namespace Modules\CheckerApi\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\CheckerApi\Database\Factories\CheckFactory;
use Modules\CheckerApi\Enums\CheckStatus;
use Override;

/**
 * A purchasable query in the catalog — what the user sees and picks.
 *
 * Global on purpose: the operator defines the catalog and every user sees the
 * same one, so it is not tenant-scoped. It is listed in
 * TenantManager::UNSCOPED_MODELS with that reason.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property string|null $category
 * @property string $provider_key
 * @property int $price_cents
 * @property CheckStatus $status
 * @property int $timeout_seconds
 */
#[Fillable(['slug', 'name', 'description', 'category', 'provider_key', 'price_cents', 'status', 'timeout_seconds'])]
class Check extends Model
{
    /** @use HasFactory<CheckFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'status' => CheckStatus::class,
        ];
    }
}
