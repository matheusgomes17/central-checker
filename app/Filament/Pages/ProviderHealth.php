<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Database\Query\Builder;
use Modules\CheckerApi\Enums\ProviderOutcome;
use Modules\CheckerApi\Models\Check;
use Modules\CheckerApi\Models\CheckExecution;
use Modules\CheckerApi\Support\CircuitBreaker;
use Modules\CheckerApi\Support\ProviderRegistry;
use Override;
use UnitEnum;

/**
 * What an operator needs to know about each provider without leaving the
 * admin panel: how many checks route to it, whether the breaker has tripped,
 * and whether it has actually been answering lately.
 *
 * The first custom Page in this panel — every other admin screen is a
 * Resource over an Eloquent model. Providers are not: they live in the
 * in-memory ProviderRegistry and the breaker state lives in the cache, so
 * there is no model to build a Resource around. The table is wired to an
 * array via records() instead of a query.
 *
 * @phpstan-type ProviderRow array{key: string, checks: int, open: bool, successRate: ?float}
 */
class ProviderHealth extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static UnitEnum|string|null $navigationGroup = 'Operação';

    protected static ?string $title = 'Saúde dos Providers';

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->providerRows())
            ->columns([
                TextColumn::make('key')
                    ->label(__('Provider')),
                TextColumn::make('checks')
                    ->label(__('Checks')),
                TextColumn::make('open')
                    ->label(__('Circuito'))
                    ->state($this->breakerStateLabel(...))
                    ->badge()
                    ->color($this->breakerStateColor(...)),
                TextColumn::make('successRate')
                    ->label(__('Taxa de sucesso (7 dias)'))
                    ->state($this->successRateLabel(...)),
            ])
            ->recordActions([
                $this->healthcheckAction(),
                $this->resetarBreakerAction(),
            ]);
    }

    #[Override]
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    /**
     * One row per registered provider — the data the table's records()
     * consumes. Keyed by provider key so row actions can target a provider
     * directly instead of an opaque numeric index.
     *
     * @return array<string, array{key: string, checks: int, open: bool, successRate: ?float}>
     */
    public function providerRows(): array
    {
        $breaker = resolve(CircuitBreaker::class);

        $rows = [];

        foreach (resolve(ProviderRegistry::class)->keys() as $key) {
            $rows[$key] = [
                'key' => $key,
                'checks' => Check::query()->where('provider_key', $key)->count(),
                'open' => $breaker->isOpen($key),
                'successRate' => $this->successRate($key),
            ];
        }

        return $rows;
    }

    /**
     * Share of successful executions over the last 7 days. Null — not 0% —
     * when nothing ran in the window, because 0% would claim a failure that
     * never happened.
     */
    private function successRate(string $key): ?float
    {
        $total = CheckExecution::acrossTenants()
            ->whereHas('check', fn (Builder $query) => $query->where('provider_key', $key))
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        if ($total === 0) {
            return null;
        }

        $success = CheckExecution::acrossTenants()
            ->whereHas('check', fn (Builder $query) => $query->where('provider_key', $key))
            ->where('created_at', '>=', now()->subDays(7))
            ->where('outcome', ProviderOutcome::Success->value)
            ->count();

        return round($success / $total * 100, 1);
    }

    /**
     * @param  ProviderRow  $record
     */
    private function breakerStateLabel(array $record): string
    {
        return $record['open'] ? __('Aberto') : __('Fechado');
    }

    /**
     * @param  ProviderRow  $record
     */
    private function breakerStateColor(array $record): string
    {
        return $record['open'] ? 'danger' : 'success';
    }

    /**
     * @param  ProviderRow  $record
     */
    private function successRateLabel(array $record): string
    {
        return $record['successRate'] === null
            ? __('Sem dados')
            : "{$record['successRate']}%";
    }

    /**
     * Read-only ping: never touches the breaker, just tells the operator
     * whether the provider is answering right now.
     */
    private function healthcheckAction(): Action
    {
        return Action::make('healthcheck')
            ->label(__('Testar agora'))
            ->icon(Heroicon::OutlinedBolt)
            ->color('gray')
            ->action($this->runHealthcheck(...));
    }

    /**
     * @param  ProviderRow  $record
     */
    private function runHealthcheck(array $record): void
    {
        $answered = resolve(ProviderRegistry::class)->resolve($record['key'])->healthCheck();

        $notification = Notification::make()
            ->title($answered ? __('Provider respondeu') : __('Provider não respondeu'));

        $answered ? $notification->success() : $notification->danger();

        $notification->send();
    }

    /**
     * The manual "close the circuit" gesture — an operator's deliberate call
     * that the provider is healthy again, ahead of the cooldown expiring on
     * its own.
     */
    private function resetarBreakerAction(): Action
    {
        return Action::make('resetarBreaker')
            ->label(__('Resetar breaker'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->requiresConfirmation()
            ->action($this->runResetarBreaker(...));
    }

    /**
     * @param  ProviderRow  $record
     */
    private function runResetarBreaker(array $record): void
    {
        resolve(CircuitBreaker::class)->reset($record['key']);

        Notification::make()
            ->title(__('Breaker resetado'))
            ->success()
            ->send();

        $this->resetTable();
    }
}
