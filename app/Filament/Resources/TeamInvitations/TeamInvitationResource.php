<?php

declare(strict_types=1);

namespace App\Filament\Resources\TeamInvitations;

use App\Filament\Resources\TeamInvitations\Pages\ListTeamInvitations;
use App\Filament\Resources\TeamInvitations\Tables\TeamInvitationsTable;
use App\Models\TeamInvitation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Override;

/**
 * "I never got my invitation" is a common ticket, and answering it needs to
 * show whether one was sent, to which address, and whether it has expired.
 */
class TeamInvitationResource extends Resource
{
    protected static ?string $model = TeamInvitation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelopeOpen;

    protected static ?int $navigationSort = 30;

    #[Override]
    public static function table(Table $table): Table
    {
        return TeamInvitationsTable::configure($table);
    }

    #[Override]
    public static function canCreate(): bool
    {
        return false;
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListTeamInvitations::route('/'),
        ];
    }
}
