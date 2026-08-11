<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\SystemRole;
use App\Enums\TeamRole;
use App\Filament\Resources\ActivityLog\Pages\ListActivities;
use App\Filament\Resources\FailedJobs\Pages\ListFailedJobs;
use App\Filament\Resources\TeamInvitations\Pages\ListTeamInvitations;
use App\Filament\Resources\Teams\Pages\ListTeams;
use App\Filament\Resources\Teams\Pages\ViewTeam;
use App\Filament\Widgets\PlatformOverview;
use App\Models\FailedJob;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->actingAs($this->superAdmin());
    }

    public function test_the_teams_list_loads(): void
    {
        $teams = Team::factory()->count(3)->create();

        $component = Livewire::test(ListTeams::class);

        $component->assertOk();
        $component->assertCanSeeTableRecords($teams);
    }

    public function test_a_deleted_team_remains_visible(): void
    {
        $team = Team::factory()->create();
        $team->delete();

        Livewire::test(ListTeams::class)->assertCanSeeTableRecords([$team]);
    }

    public function test_the_team_page_shows_who_owns_it(): void
    {
        $team = Team::factory()->create(['is_personal' => false]);
        $owner = User::factory()->create(['name' => 'Ada Lovelace']);

        $team->memberships()->create(['user_id' => $owner->id]);
        $owner->assignTeamRole($team, TeamRole::Owner);

        // O Team resolve por slug, não por id: passar a chave primária aqui
        // procuraria um slug numérico e não acharia nada.
        Livewire::test(ViewTeam::class, ['record' => $team->getRouteKey()])
            ->assertOk()
            ->assertSee('Ada Lovelace');
    }

    public function test_the_invitations_list_loads(): void
    {
        $team = Team::factory()->create();
        $inviter = User::factory()->create();

        $invitation = TeamInvitation::create([
            'team_id' => $team->id,
            'email' => 'invitee@example.com',
            'role' => TeamRole::Member,
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(3),
        ]);

        $component = Livewire::test(ListTeamInvitations::class);

        $component->assertOk();
        $component->assertCanSeeTableRecords([$invitation]);
    }

    /**
     * The trail is deliberately read-only: an audit log an operator could edit
     * answers nothing, because the first question anyone asks of one is whether
     * it could have been changed.
     */
    public function test_the_audit_trail_is_read_only(): void
    {
        Livewire::test(ListActivities::class)->assertOk();

        $resource = \App\Filament\Resources\ActivityLog\ActivityResource::class;

        $this->assertFalse($resource::canCreate());
        $this->assertSame(['index'], array_keys($resource::getPages()));
    }

    public function test_the_failed_jobs_list_loads(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => Str::uuid()->toString(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\SendReport']),
            'exception' => "RuntimeException: boom\n#0 somewhere",
            'failed_at' => now(),
        ]);

        $component = Livewire::test(ListFailedJobs::class);

        $component->assertOk();
        $component->assertCanSeeTableRecords(FailedJob::all());
        $component->assertSee('App\\Jobs\\SendReport');
    }

    public function test_the_overview_widget_counts_signups_and_teams(): void
    {
        User::factory()->count(2)->create();
        Team::factory()->count(3)->create(['is_personal' => false]);

        Livewire::test(PlatformOverview::class)->assertOk();
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->setSystemRole(SystemRole::SuperAdmin);
        $user->refresh();

        return $user;
    }
}
