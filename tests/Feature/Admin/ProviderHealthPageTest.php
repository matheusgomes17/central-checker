<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\SystemRole;
use App\Filament\Pages\ProviderHealth;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\CheckerApi\Enums\ProviderOutcome;
use Modules\CheckerApi\Models\Check;
use Modules\CheckerApi\Models\CheckExecution;
use Modules\CheckerApi\Support\CircuitBreaker;
use Modules\CheckerApi\Support\FakeProvider;
use Modules\CheckerApi\Support\ProviderRegistry;
use Modules\CheckerApi\Support\ProviderResult;
use RuntimeException;
use Tests\TestCase;

/**
 * Providers are not Eloquent — they come from the in-memory ProviderRegistry
 * and the circuit breaker state lives in the cache, so every scenario here
 * registers its own fakes instead of relying on seeded rows.
 */
class ProviderHealthPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->actingAs($this->superAdmin());

        resolve(ProviderRegistry::class)->register(
            FakeProvider::returning(ProviderResult::success(['nome' => 'Fulano']))->withKey('serasa'),
        );
    }

    public function test_the_page_loads_and_lists_registered_providers(): void
    {
        resolve(ProviderRegistry::class)->register(
            FakeProvider::returning(ProviderResult::noData())->withKey('spc'),
        );

        Livewire::test(ProviderHealth::class)
            ->assertOk()
            ->assertSee('serasa')
            ->assertSee('spc');
    }

    public function test_it_shows_the_breaker_state(): void
    {
        resolve(ProviderRegistry::class)->register(
            FakeProvider::returning(ProviderResult::noData())->withKey('spc'),
        );

        $breaker = resolve(CircuitBreaker::class);

        for ($i = 0; $i < CircuitBreaker::FAILURE_THRESHOLD; $i++) {
            $breaker->recordFailure('serasa');
        }

        Livewire::test(ProviderHealth::class)
            ->assertOk()
            ->assertSee('Aberto')
            ->assertSee('Fechado');
    }

    public function test_the_healthcheck_action_reflects_the_provider_answer(): void
    {
        resolve(ProviderRegistry::class)->register(
            FakeProvider::throwing(new RuntimeException('fora do ar'))->withKey('offline'),
        );

        Livewire::test(ProviderHealth::class)
            ->callAction(TestAction::make('healthcheck')->table('serasa'))
            ->assertNotified('Provider respondeu');

        Livewire::test(ProviderHealth::class)
            ->callAction(TestAction::make('healthcheck')->table('offline'))
            ->assertNotified('Provider não respondeu');
    }

    public function test_resetting_the_breaker_closes_it(): void
    {
        $breaker = resolve(CircuitBreaker::class);

        for ($i = 0; $i < CircuitBreaker::FAILURE_THRESHOLD; $i++) {
            $breaker->recordFailure('serasa');
        }

        $this->assertTrue($breaker->isOpen('serasa'));

        Livewire::test(ProviderHealth::class)
            ->callAction(TestAction::make('resetarBreaker')->table('serasa'))
            ->assertNotified();

        $this->assertFalse($breaker->isOpen('serasa'));
    }

    public function test_the_success_rate_reflects_executions(): void
    {
        $check = Check::factory()->create(['provider_key' => 'serasa']);

        CheckExecution::factory()->count(2)->create([
            'check_id' => $check->id,
            'outcome' => ProviderOutcome::Success,
            'created_at' => now()->subDay(),
        ]);

        CheckExecution::factory()->count(2)->create([
            'check_id' => $check->id,
            'outcome' => ProviderOutcome::Failed,
            'created_at' => now()->subDay(),
        ]);

        // Fora da janela de 7 dias: não deve entrar na conta.
        CheckExecution::factory()->create([
            'check_id' => $check->id,
            'outcome' => ProviderOutcome::Success,
            'created_at' => now()->subDays(10),
        ]);

        Livewire::test(ProviderHealth::class)
            ->assertOk()
            ->assertSee('50');
    }

    public function test_the_page_stays_shut_for_an_ordinary_user(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(ProviderHealth::getUrl())->assertForbidden();
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->setSystemRole(SystemRole::SuperAdmin);
        $user->refresh();

        return $user;
    }
}
