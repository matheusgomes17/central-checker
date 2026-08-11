<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Actions\Teams\ImportInvitations;
use App\Enums\TeamRole;
use App\Exceptions\PlanLimitException;
use App\Jobs\Imports\ImportInvitationChunk;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ImportInvitationsTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->team = Team::factory()->create(['is_personal' => false]);
        $this->owner = User::factory()->create();

        $this->team->memberships()->create(['user_id' => $this->owner->id]);
        $this->owner->assignTeamRole($this->team, TeamRole::Owner);
    }

    /**
     * People export these lists from everywhere, so the parser is forgiving
     * about shape: a header row, extra columns and blank lines are all things
     * nobody considers their problem.
     */
    public function test_it_reads_addresses_out_of_a_messy_csv(): void
    {
        $csv = <<<'CSV'
        name,email,department
        Ada Lovelace,ada@example.com,Engineering
        Grace Hopper,grace@example.com,Engineering

        ,not-an-email,
        Alan Turing,alan@example.com,Research
        CSV;

        $emails = resolve(ImportInvitations::class)->parse($csv);

        $this->assertSame(['ada@example.com', 'grace@example.com', 'alan@example.com'], $emails);
    }

    /**
     * Real spreadsheets leave blank space around a cell all the time — a
     * column padded for alignment, a copy-paste from another sheet. The
     * address has to come out clean, not carrying the padding along with it.
     */
    public function test_it_trims_blank_space_surrounding_an_address(): void
    {
        $csv = 'Ada Lovelace, ada@example.com ,Engineering';

        $emails = resolve(ImportInvitations::class)->parse($csv);

        $this->assertSame(['ada@example.com'], $emails);
    }

    /**
     * A chunk is small enough to retry and big enough to be worth putting on
     * a queue. One job per row is overhead; one job for everything cannot
     * resume.
     */
    public function test_it_chunks_the_list_into_batchable_jobs(): void
    {
        Bus::fake();

        // Cento e vinte pessoas novas pedem cento e vinte assentos de verdade,
        // então o teto continua recusando com razão. Ele sai de cena porque o
        // que se mede aqui é a divisão em chunks, e ele tem teste próprio.
        config(['billing.plans.free.limits.members' => null]);

        $emails = array_map(fn (int $i): string => "person{$i}@example.com", range(1, 120));

        resolve(ImportInvitations::class)->handle($this->team, $this->owner, $emails, TeamRole::Member);

        Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->count() === 3);
    }

    /**
     * The batch's name is what identifies this import in Horizon — nobody
     * looking at a wall of running batches can tell "invitations:7" from
     * "invitations:12" apart without the team id actually being in it.
     */
    public function test_the_batch_is_named_after_the_team(): void
    {
        Bus::fake();

        resolve(ImportInvitations::class)
            ->handle($this->team, $this->owner, ['a@example.com'], TeamRole::Member);

        Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->name === "invitations:{$this->team->id}");
    }

    public function test_the_jobs_carry_the_team(): void
    {
        Bus::fake();

        resolve(ImportInvitations::class)
            ->handle($this->team, $this->owner, ['a@example.com'], TeamRole::Member);

        Bus::assertBatched(function (PendingBatch $batch): bool {
            $job = $batch->jobs->first();

            return $job instanceof ImportInvitationChunk && $job->tenantId === $this->team->id;
        });
    }

    public function test_duplicates_and_blanks_are_collapsed_before_dispatch(): void
    {
        Bus::fake();

        resolve(ImportInvitations::class)->handle($this->team, $this->owner, [
            'ada@example.com', ' ADA@example.com ', '', 'grace@example.com',
        ], TeamRole::Member);

        Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->count() === 1);
    }

    public function test_running_a_chunk_creates_the_invitations(): void
    {
        Notification::fake();

        $job = new ImportInvitationChunk(['ada@example.com', 'grace@example.com'], TeamRole::Member, $this->owner->id)
            ->forTenant($this->team);

        $job->handle();

        $this->assertDatabaseHas('team_invitations', ['team_id' => $this->team->id, 'email' => 'ada@example.com']);
        $this->assertSame(2, TeamInvitation::query()->where('team_id', $this->team->id)->count());
    }

    /**
     * Re-inviting means a second email to someone who already got the first.
     */
    public function test_it_skips_people_already_invited_or_already_in_the_team(): void
    {
        Notification::fake();

        // O time nasce exatamente no teto do Free (dono, membro e um convite de
        // pé). Uma vaga a mais em vez de teto nenhum: o único convite que este
        // teste espera ver criado cabe, e a verificação continua ligada para o
        // caso de o pulo deixar de acontecer.
        config(['billing.plans.free.limits.members' => 4]);

        $member = User::factory()->create(['email' => 'member@example.com']);
        $this->team->memberships()->create(['user_id' => $member->id]);
        $member->assignTeamRole($this->team, TeamRole::Member);

        $this->team->invitations()->create([
            'email' => 'pending@example.com',
            'role' => TeamRole::Member,
            'invited_by' => $this->owner->id,
            'expires_at' => now()->addDays(3),
        ]);

        $job = new ImportInvitationChunk(
            ['member@example.com', 'pending@example.com', 'new@example.com'],
            TeamRole::Member,
            $this->owner->id,
        )->forTenant($this->team);

        $job->handle();

        $this->assertSame(2, TeamInvitation::query()->where('team_id', $this->team->id)->count());
        $this->assertDatabaseHas('team_invitations', ['email' => 'new@example.com']);
    }

    /**
     * The counter frees the seat the moment an invitation lapses, so the import
     * has to be able to fill it again. Skipping on accepted_at alone meant an
     * address whose invitation ran out could never be re-invited by a sheet —
     * the ceiling said the seat was free and the import refused to use it.
     */
    public function test_a_lapsed_invitation_is_sent_again_by_an_import(): void
    {
        Notification::fake();

        $this->team->invitations()->create([
            'email' => 'lapsed@example.com',
            'role' => TeamRole::Member,
            'invited_by' => $this->owner->id,
            'expires_at' => now()->subDay(),
        ]);

        $job = new ImportInvitationChunk(['lapsed@example.com'], TeamRole::Member, $this->owner->id)
            ->forTenant($this->team);

        $job->handle();

        $this->assertSame(2, TeamInvitation::query()->where('email', 'lapsed@example.com')->count());
    }

    public function test_an_invalid_address_inside_a_chunk_is_skipped_rather_than_failing_the_job(): void
    {
        Notification::fake();

        $job = new ImportInvitationChunk(['not-an-email', 'ada@example.com'], TeamRole::Member, $this->owner->id)
            ->forTenant($this->team);

        $job->handle();

        $this->assertSame(1, TeamInvitation::query()->count());
    }

    /**
     * Cancelling has to stop what is already queued, not only stop dispatching
     * more.
     */
    public function test_a_cancelled_batch_stops_inviting(): void
    {
        Notification::fake();

        $emails = ['ada@example.com'];

        $batch = Bus::batch([])->name('test')->dispatch();
        $batch->cancel();

        $job = new ImportInvitationChunk($emails, TeamRole::Member, $this->owner->id)
            ->forTenant($this->team);

        $job->withBatchId($batch->id)->handle();

        $this->assertSame(0, TeamInvitation::query()->count());
    }

    /**
     * One bad row must not take the rest of the list down with it.
     */
    public function test_the_batch_tolerates_failures(): void
    {
        Bus::fake();

        resolve(ImportInvitations::class)
            ->handle($this->team, $this->owner, ['a@example.com'], TeamRole::Member);

        Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->allowsFailures());
    }

    /**
     * Whole batch or nothing. Accepting up to the ceiling leaves the question
     * "which ones went in", and no screen can answer it.
     */
    public function test_a_batch_that_crosses_the_ceiling_is_refused_whole(): void
    {
        $emails = ['a@example.test', 'b@example.test', 'c@example.test', 'd@example.test'];

        try {
            resolve(ImportInvitations::class)->handle($this->team, $this->owner, $emails, TeamRole::Member);
            $this->fail('A importação deveria ter sido recusada.');
        } catch (PlanLimitException $e) {
            $this->assertStringContainsString('Free', $e->getMessage());
        }

        $this->assertSame(0, TeamInvitation::query()->whereIn('email', $emails)->count());
    }

    /**
     * The message carries the numbers, same as the individual invite's own
     * version of this test (`PlanLimitTest::test_the_message_names_the_plan_and_the_ceiling`).
     * "Upgrade to continue" with no plan name and no ceiling is the kind of
     * wall people open support tickets about — an import refusal is not
     * exempt just because it is refusing a whole sheet instead of one row.
     */
    public function test_the_message_names_the_plan_and_the_ceiling(): void
    {
        $emails = ['a@example.test', 'b@example.test', 'c@example.test', 'd@example.test'];

        try {
            resolve(ImportInvitations::class)->handle($this->team, $this->owner, $emails, TeamRole::Member);
            $this->fail('A importação deveria ter sido recusada.');
        } catch (PlanLimitException $e) {
            $this->assertStringContainsString('Free', $e->getMessage());
            $this->assertStringContainsString('3', $e->getMessage());
        }
    }

    /**
     * The chunk job also refuses over the ceiling, so a batch that slips past
     * this check would still fail — just later, and only after some of the
     * batch's chunks had already reached the queue. Faking the bus makes sure
     * this test is about the entry check specifically: refused before
     * anything is dispatched at all, not refused eventually.
     */
    public function test_a_batch_that_crosses_the_ceiling_never_reaches_the_queue(): void
    {
        Bus::fake();

        $emails = ['a@example.test', 'b@example.test', 'c@example.test', 'd@example.test'];

        try {
            resolve(ImportInvitations::class)->handle($this->team, $this->owner, $emails, TeamRole::Member);
        } catch (PlanLimitException) {
            // esperado
        }

        Bus::assertNothingBatched();
    }

    /**
     * Re-importing a corrected sheet is the commonest import there is, and the
     * corrected sheet still names everyone. Counting the whole file counts
     * people the job skips without creating anything, so a team of one with a
     * ceiling of three was refused a sheet whose real effect is one invitation.
     *
     * The refusal stays whole-batch. What changed is what the batch is measured
     * by: the seats it asks for, not the rows it contains.
     */
    public function test_a_batch_is_measured_by_the_seats_it_actually_asks_for(): void
    {
        Bus::fake();

        $this->team->invitations()->create([
            'email' => 'waiting@example.com',
            'role' => TeamRole::Member,
            'invited_by' => $this->owner->id,
            'expires_at' => now()->addDays(3),
        ]);

        // Dono mais convite de pé: 2 de 3. A planilha repete os dois e traz
        // uma pessoa nova, que é o único assento que ela de fato pede.
        resolve(ImportInvitations::class)->handle($this->team, $this->owner, [
            $this->owner->email, 'waiting@example.com', 'new@example.test',
        ], TeamRole::Member);

        Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->count() === 1);
    }

    public function test_a_batch_that_fits_is_imported(): void
    {
        Bus::fake();

        resolve(ImportInvitations::class)->handle($this->team, $this->owner, ['cabe@example.test'], TeamRole::Member);

        Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->count() > 0);
    }

    /**
     * The batch's entry point already checked the whole list against the
     * ceiling; this is what covers what slips past it — another import, or a
     * standalone invite, landing between dispatch and this job running. The
     * job must fail rather than quietly stop short of the ceiling, so the
     * failure shows up where failed jobs show up.
     */
    public function test_running_a_chunk_that_crosses_the_ceiling_during_execution_throws(): void
    {
        Notification::fake();

        // Time no teto do Free (3): o dono mais estes dois preenchem as vagas.
        $this->team->memberships()->create(['user_id' => User::factory()->create()->id]);
        $this->team->memberships()->create(['user_id' => User::factory()->create()->id]);

        $job = new ImportInvitationChunk(['new@example.com'], TeamRole::Member, $this->owner->id)
            ->forTenant($this->team);

        try {
            $job->handle();
            $this->fail('O job deveria ter lançado PlanLimitException.');
        } catch (PlanLimitException) {
            // esperado
        }

        $this->assertSame(0, TeamInvitation::query()->where('email', 'new@example.com')->count());
    }
}
