<?php

declare(strict_types=1);

namespace App\Filament\Resources\TeamInvitations\Pages;

use App\Filament\Resources\TeamInvitations\TeamInvitationResource;
use Filament\Resources\Pages\ListRecords;

class ListTeamInvitations extends ListRecords
{
    protected static string $resource = TeamInvitationResource::class;
}
