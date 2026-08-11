<?php

declare(strict_types=1);

namespace App\Filament\Resources\TeamInvitations\Tables;

use App\Actions\Teams\ResendTeamInvitation;
use App\Exceptions\InvitationResendException;
use App\Exceptions\PlanLimitException;
use App\Models\TeamInvitation;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TeamInvitationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')->searchable()->copyable()->sortable(),
                TextColumn::make('team.name')->label(__('Team'))->searchable()->sortable(),
                TextColumn::make('role')->badge()->sortable(),
                TextColumn::make('inviter.name')->label(__('Invited by'))->toggleable(),
                TextColumn::make('created_at')->label(__('Sent'))->dateTime()->sortable(),
                TextColumn::make('expires_at')
                    ->label(__('Expires'))
                    ->dateTime()
                    ->sortable()
                    ->badge()
                    ->color(fn (TeamInvitation $record): string => $record->isExpired() ? 'danger' : 'success'),
                TextColumn::make('accepted_at')
                    ->label(__('Accepted'))
                    ->dateTime()
                    ->placeholder(__('Pending'))
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TernaryFilter::make('accepted_at')
                    ->label(__('Accepted'))
                    ->nullable(),
                Filter::make('expired')
                    ->label(__('Expired'))
                    ->query(fn (Builder $query): Builder => $query
                        ->whereNotNull('expires_at')
                        ->where('expires_at', '<', now())),
                SelectFilter::make('team')
                    ->relationship('team', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                self::resend(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * The same action the team screen uses, cooldown and all.
     *
     * Reaching past it into a fresh notification would give the panel a way to
     * send mail that the application's own rules would have refused.
     */
    private static function resend(): Action
    {
        return Action::make('resend')
            ->label(__('Resend'))
            ->icon('heroicon-o-paper-airplane')
            ->requiresConfirmation()
            ->visible(fn (TeamInvitation $record): bool => $record->accepted_at === null)
            ->action(function (TeamInvitation $record, ResendTeamInvitation $resend): void {
                try {
                    $resend->handle($record);
                } catch (InvitationResendException|PlanLimitException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title(__('Invitation sent again.'))->success()->send();
            });
    }
}
