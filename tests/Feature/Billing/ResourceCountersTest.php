<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Support\Billing\Plan;
use App\Support\Billing\ResourceCounters;
use App\Support\Billing\TeamBilling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * A plan that names a limit nobody can count is a plan that promises a ceiling
 * and enforces nothing. The consistency test is what turns that from a silent
 * zero into a failure.
 */
class ResourceCountersTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_pending_invitation_counts_against_the_ceiling(): void
    {
        $team = Team::factory()->create();
        $owner = User::factory()->create();

        $team->members()->attach($owner);
        $owner->assignTeamRole($team, TeamRole::Owner);

        $team->invitations()->create([
            'email' => 'convidada@example.test',
            'role' => TeamRole::Member,
            'invited_by' => $owner->id,
            'expires_at' => now()->addDays(7),
        ]);

        // Um membro e um convite pendente: o time já ocupa dois dos três
        // assentos do plano gratuito, mesmo com uma pessoa só dentro.
        $this->assertSame(2, TeamBilling::for($team)->usage('members')['used']);
    }

    public function test_an_expired_invitation_frees_the_seat(): void
    {
        $team = Team::factory()->create();
        $owner = User::factory()->create();

        $team->members()->attach($owner);
        $owner->assignTeamRole($team, TeamRole::Owner);

        $team->invitations()->create([
            'email' => 'expirada@example.test',
            'role' => TeamRole::Member,
            'invited_by' => $owner->id,
            'expires_at' => now()->subDay(),
        ]);

        $this->assertSame(1, TeamBilling::for($team)->usage('members')['used']);
    }

    public function test_an_accepted_invitation_is_not_counted_twice(): void
    {
        $team = Team::factory()->create();
        $owner = User::factory()->create();

        $team->members()->attach($owner);
        $owner->assignTeamRole($team, TeamRole::Owner);

        $team->invitations()->create([
            'email' => 'aceita@example.test',
            'role' => TeamRole::Member,
            'invited_by' => $owner->id,
            'expires_at' => now()->addDays(7),
            'accepted_at' => now(),
        ]);

        $this->assertSame(1, TeamBilling::for($team)->usage('members')['used']);
    }

    public function test_a_resource_with_no_counter_is_an_error_and_not_a_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);

        resolve(ResourceCounters::class)->countFor('projects', Team::factory()->create());
    }

    /**
     * The rule the config has to obey: name a limit only for something the
     * product can count. Adding `projects` to a plan without registering a
     * counter fails here instead of reporting zero use forever.
     */
    public function test_every_limit_any_plan_names_has_a_counter(): void
    {
        $registered = resolve(ResourceCounters::class)->registered();

        foreach (Plan::all() as $plan) {
            foreach (array_keys($plan->limits) as $resource) {
                $this->assertContains(
                    $resource,
                    $registered,
                    "O plano [{$plan->key}] limita [{$resource}] e não há contador registrado para esse recurso.",
                );
            }
        }
    }
}
