<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Enums\TeamRole;
use App\Exceptions\PlanLimitException;
use App\Jobs\Imports\ImportInvitationChunk;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\Billing\TeamBilling;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use RuntimeException;
use Throwable;

/**
 * Turns a list of addresses into a batch of invitation jobs.
 *
 * The parsing happens here, synchronously, so a malformed file is refused
 * while the person is still looking at the screen. Only the sending is
 * queued — an import that accepts garbage and fails later in a worker gives
 * the person nothing to act on.
 */
class ImportInvitations
{
    public const int CHUNK = 50;

    /**
     * @param  array<int, string>  $emails
     */
    public function handle(Team $team, User $invitedBy, array $emails, TeamRole $role): Batch
    {
        $clean = $this->clean($emails);

        $billing = TeamBilling::for($team);

        // O lote inteiro ou nada. Aceitar até encher deixa a pergunta "quais
        // entraram", e não há tela que responda isso. Mas "inteiro" é medido
        // pelos assentos que o lote pede, não pelas linhas que ele tem.
        if (! $billing->withinLimit('members', count($this->seatsAskedBy($team, $clean)))) {
            throw PlanLimitException::reached('members', $billing->plan(), $billing->plan()->limit('members') ?? 0);
        }

        $chunks = array_chunk($clean, self::CHUNK);

        $jobs = array_map(
            fn (array $chunk): ImportInvitationChunk => new ImportInvitationChunk($chunk, $role, $invitedBy->id)
                ->forTenant($team),
            $chunks,
        );

        return Bus::batch($jobs)
            ->name('invitations:'.$team->id)
            ->onQueue('default')
            // Uma linha ruim não deve derrubar o lote inteiro: o resto das
            // pessoas ainda precisa ser convidado.
            ->allowFailures()
            ->finally(function (Batch $batch) use ($team): void {
                report_if($batch->failedJobs > 0, new RuntimeException(
                    "Importação de convites do time {$team->id} terminou com {$batch->failedJobs} falha(s)."
                ));
            })
            ->dispatch();
    }

    /**
     * The addresses in the list that would actually take a seat.
     *
     * People who are already members, and people whose invitation is still
     * standing, cost nothing: the chunk job skips them and creates nothing.
     * Counting them anyway refuses a sheet that fits — and re-importing a
     * corrected sheet, which still names everyone, is the commonest import
     * there is.
     *
     * A lapsed invitation is not skipped here, because it is not skipped by the
     * job either: renewing it takes the seat back.
     *
     * The batch still carries the whole list. The job is the authority on who
     * to skip, and it has to re-decide anyway — between this check and its own
     * execution, someone may have accepted, been invited, or joined.
     *
     * @param  array<int, string>  $clean
     * @return array<int, string>
     */
    private function seatsAskedBy(Team $team, array $clean): array
    {
        $members = $team->members()
            ->whereIn('users.email', $clean)
            ->get()
            ->map(fn (User $member): string => $member->email)
            ->all();

        $invited = $team->invitations()
            ->pending()
            ->whereIn('email', $clean)
            ->get()
            ->map(fn (TeamInvitation $invitation): string => $invitation->email)
            ->all();

        return array_values(array_diff($clean, $members, $invited));
    }

    /**
     * @param  array<int, string>  $emails
     * @return array<int, string>
     */
    private function clean(array $emails): array
    {
        $cleaned = [];

        foreach ($emails as $email) {
            $email = mb_strtolower(trim($email));

            if ($email !== '') {
                $cleaned[$email] = true;
            }
        }

        return array_keys($cleaned);
    }

    /**
     * Reads addresses out of an uploaded CSV.
     *
     * Deliberately forgiving about the shape: people export these from
     * everywhere, and refusing a file because it has a header row or extra
     * columns would fail for a reason nobody considers their problem.
     *
     * @return array<int, string>
     */
    public function parse(string $contents): array
    {
        $emails = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            foreach (str_getcsv($line, ',', '"', '\\') as $field) {
                if (is_string($field) && filter_var(trim($field), FILTER_VALIDATE_EMAIL)) {
                    $emails[] = trim($field);
                }
            }
        }

        return $emails;
    }

    public function progressOf(string $batchId): ?Batch
    {
        try {
            return Bus::findBatch($batchId);
        } catch (Throwable) {
            return null;
        }
    }
}
