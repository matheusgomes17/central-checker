<?php

declare(strict_types=1);

namespace App\Filament\Resources\ActivityLog\Tables;

use App\Enums\AuditEvent;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

class ActivitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label(__('When'))->dateTime()->sortable(),
                TextColumn::make('event')
                    ->label(__('Event'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null
                        ? '—'
                        : (AuditEvent::tryFrom($state)?->label() ?? $state))
                    ->sortable(),
                TextColumn::make('causer.name')->label(__('By'))->placeholder(__('System'))->searchable(),
                TextColumn::make('subject_type')
                    ->label(__('Subject'))
                    ->formatStateUsing(fn (?string $state): string => class_basename((string) $state))
                    ->description(fn (Activity $record): string => '#'.$record->subject_id),
                TextColumn::make('properties')
                    ->label(__('Details'))
                    ->formatStateUsing(fn (mixed $state): string => json_encode($state, JSON_UNESCAPED_UNICODE) ?: '')
                    ->wrap()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('event')
                    ->label(__('Event'))
                    ->options(fn (): array => collect(AuditEvent::cases())
                        ->mapWithKeys(fn (AuditEvent $e): array => [$e->value => $e->label()])
                        ->all()),
                Filter::make('period')
                    ->schema([
                        DatePicker::make('from')->label(__('From')),
                        DatePicker::make('until')->label(__('Until')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $from = $data['from'] ?? null;
                        $until = $data['until'] ?? null;

                        if (is_string($from)) {
                            $query->whereDate('created_at', '>=', $from);
                        }

                        if (is_string($until)) {
                            $query->whereDate('created_at', '<=', $until);
                        }

                        return $query;
                    }),
            ]);
    }
}
