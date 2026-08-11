<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Support\Observability\SentryContext;
use App\Support\TenantManager;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Sentry\Event;
use Sentry\State\Scope;
use Sentry\UserDataBag;
use Tests\TestCase;

/**
 * The acceptance criterion for this phase is an alert carrying tenant context.
 * An exception report that says only what broke sends whoever is on call
 * looking through logs to find who it broke for.
 */
class SentryContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_alert_carries_the_team(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $team = Team::factory()->create(['is_personal' => false]);
        $user = User::factory()->create();

        $team->memberships()->create(['user_id' => $user->id]);
        $user->assignTeamRole($team, TeamRole::Owner);

        $this->actingAs($user);
        resolve(TenantManager::class)->set($team);

        $tags = $this->tagsFrom();

        $this->assertSame((string) $team->id, $tags['team_id'] ?? null);
    }

    /**
     * Without a tenant the tag still has to be there. A missing key reads as
     * "we did not record it"; "none" reads as "there was none", and the two
     * lead to different investigations.
     */
    public function test_a_request_without_a_team_says_so(): void
    {
        $tags = $this->tagsFrom();

        $this->assertSame('none', $tags['team_id'] ?? null);
    }

    /**
     * The same label the log lines carry, so the alert leads to them without
     * searching by timestamp.
     */
    public function test_the_alert_carries_the_request_identifier(): void
    {
        Context::add('request_id', 'trace-c');

        $tags = $this->tagsFrom();

        $this->assertSame('trace-c', $tags['request_id'] ?? null);
    }

    /**
     * The id and nothing else. Sending someone's name and email to a third
     * party is a decision about their data, not a default.
     */
    public function test_the_user_goes_by_id_and_nothing_else(): void
    {
        $user = User::factory()->create(['name' => 'Ada', 'email' => 'ada@example.com']);

        $this->actingAs($user);

        $scope = new Scope;
        resolve(SentryContext::class)->applyTo($scope);

        $sentUser = $scope->applyToEvent(Event::createEvent())?->getUser();

        $this->assertInstanceOf(UserDataBag::class, $sentUser);
        $this->assertSame($user->id, $sentUser->getId());
        $this->assertNull($sentUser->getEmail());
        $this->assertNull($sentUser->getUsername());
    }

    public function test_send_default_pii_stays_off(): void
    {
        $this->assertFalse(config('sentry.send_default_pii'));
    }

    /**
     * @return array<string, string>
     */
    private function tagsFrom(): array
    {
        $scope = new Scope;

        resolve(SentryContext::class)->applyTo($scope);

        return $scope->applyToEvent(Event::createEvent())?->getTags() ?? [];
    }
}
