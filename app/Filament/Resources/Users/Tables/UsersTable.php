<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Built around the support desk, not around the model.
 *
 * The columns and actions here are the answers to the questions support
 * actually gets asked — "I cannot sign in", "I never got the email", "what
 * teams am I in", "can you look at my account" — so that answering them never
 * requires a database client.
 */
class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                IconColumn::make('email_verified_at')
                    ->label(__('Verified'))
                    ->boolean()
                    ->sortable(),
                IconColumn::make('two_factor_confirmed_at')
                    ->label(__('2FA'))
                    ->boolean()
                    ->sortable(),
                IconColumn::make('is_super_admin')
                    ->label(__('Admin'))
                    ->boolean()
                    ->sortable(),
                TextColumn::make('teams_count')
                    ->label(__('Teams'))
                    ->counts('teams')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('Signed up'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('deleted_at')
                    ->label(__('Deleted'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                // Padrão do filtro é esconder excluídos. Aqui a lista já nasce
                // mostrando tudo: "excluí minha conta sem querer" é um pedido
                // de suporte, e ele não se responde numa lista que esconde a
                // conta procurada.
                TrashedFilter::make()->default(true),
                TernaryFilter::make('email_verified_at')
                    ->label(__('Email verified'))
                    ->nullable(),
                Filter::make('is_super_admin')
                    ->label(__('Platform administrators'))
                    ->query(fn (Builder $query): Builder => $query->where('is_super_admin', true)),
            ])
            ->recordActions([
                ViewAction::make(),
                self::impersonate(),
                self::resendVerification(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::exportCsv(),
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    /**
     * The missing entry point for a feature that already existed.
     *
     * Impersonation was built with routes, a trail and a banner, but nothing
     * to start it from. This is that button, and it hides itself for exactly
     * the cases the action refuses anyway.
     */
    private static function impersonate(): Action
    {
        return Action::make('impersonate')
            ->label(__('Impersonate'))
            ->icon('heroicon-o-identification')
            ->requiresConfirmation()
            ->modalDescription(__('You will be signed in as this user, and it will be recorded.'))
            ->visible(fn (User $record): bool => ! $record->is_super_admin && $record->deleted_at === null)
            ->url(fn (User $record): string => route('impersonate.start', $record))
            ->postToUrl();
    }

    private static function resendVerification(): Action
    {
        return Action::make('resendVerification')
            ->label(__('Resend verification'))
            ->icon('heroicon-o-envelope')
            ->visible(fn (User $record): bool => $record->email_verified_at === null && $record->deleted_at === null)
            ->requiresConfirmation()
            ->action(function (User $record): void {
                $record->sendEmailVerificationNotification();

                Notification::make()
                    ->title(__('Verification email sent.'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Streams the selection instead of putting an export job on the queue.
     *
     * Filament's exporter is the richer tool, but it needs its own tables and
     * a working queue. A boilerplate should not make those a precondition for
     * getting a list of users out.
     */
    private static function exportCsv(): BulkAction
    {
        return BulkAction::make('exportCsv')
            ->label(__('Export CSV'))
            ->icon('heroicon-o-arrow-down-tray')
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records): StreamedResponse => response()->streamDownload(
                fn () => self::writeCsv($records),
                'users-'.now()->format('Y-m-d-His').'.csv',
            ));
    }

    /**
     * @param  Collection<int, Model>  $records
     */
    private static function writeCsv(Collection $records): void
    {
        $handle = fopen('php://output', 'w');

        if ($handle === false) {
            return;
        }

        fputcsv($handle, ['id', 'name', 'email', 'verified_at', 'created_at'], escape: '\\');

        foreach ($records as $record) {
            // A seleção vem tipada como Model genérico; o instanceof é o que
            // dá acesso às colunas sem sobrescrever o tipo à força.
            if (! $record instanceof User) {
                continue;
            }

            fputcsv($handle, [
                $record->id,
                $record->name,
                $record->email,
                $record->email_verified_at?->toIso8601String(),
                $record->created_at?->toIso8601String(),
            ],
                escape: '\\');
        }

        fclose($handle);
    }
}
