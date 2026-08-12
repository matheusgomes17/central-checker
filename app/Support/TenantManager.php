<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ApiToken;
use App\Models\FailedJob;
use App\Models\Membership;
use App\Models\PlanCheck;
use App\Models\PlanSubscription;
use App\Models\SocialAccount;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;

/**
 * Resolves which team the current execution belongs to.
 *
 * Registered as a singleton, so everything in one request or one job agrees on
 * the answer. Web requests fall back to the signed in user's current team;
 * anything else — console commands, queued jobs, API tokens — has to say so
 * explicitly with set().
 */
class TenantManager
{
    /**
     * The column that ties a row to its team.
     */
    public const string COLUMN = 'team_id';

    /**
     * Models that carry a team column but are deliberately not scoped.
     *
     * One source for two consumers: the architecture rule reads this to know
     * who may skip the trait, and `tenant:audit` reads it to know which tables
     * not to flag. Two separate lists would drift, and the one that drifted
     * quietly would be the one doing the protecting.
     *
     * Every entry needs a reason as plain as the ones below. A list that grows
     * without them has stopped protecting anything.
     *
     * @var array<int, class-string<Model>>
     */
    public const array UNSCOPED_MODELS = [
        // O tenant em si. Escopar Team pelo próprio Team não faz sentido.
        Team::class,
        // Pessoas atravessam times: um usuário pertence a vários. Quem separa
        // o acesso aqui são as memberships, não um team_id no usuário.
        User::class,
        // A própria tabela de junção que define o vínculo usuário↔time.
        Membership::class,
        // Convite existe antes de haver membro, e é buscado por código durante
        // o fluxo público de aceite, quando ainda não há tenant no contexto.
        TeamInvitation::class,
        // Identidade de OAuth pertence à pessoa, não ao time — é resolvida no
        // login, antes de qualquer tenant existir.
        SocialAccount::class,
        // Tabela do framework, exposta como model só para o painel conseguir
        // listar. Job falho não pertence a time nenhum.
        FailedJob::class,
        // O token carrega o time, mas é resolvido antes de haver tenant — é
        // ele quem define qual será. Escopá-lo pelo tenant atual seria pedir
        // que ele já soubesse a resposta que veio buscar.
        ApiToken::class,
        // Catálogo global: o operador define os checks e todos os times veem
        // o mesmo conjunto. Não pertence a time nenhum, então não é escopado.
        \Modules\CheckerApi\Models\Check::class,
        // Assinatura pré-paga: pertence ao time, mas é resolvida pelo webhook de
        // pagamento sem nenhum tenant no contexto. Quem filtra o acesso é a
        // relação team(), não o escopo global.
        PlanSubscription::class,
        // Catálogo global plano↔check: o operador define quais checks cada
        // plano inclui, e todos os times leem a mesma tabela. A franquia é
        // resolvida pela camada de billing sem tenant no contexto — mesma
        // razão da entrada de Check acima.
        PlanCheck::class,
    ];

    /**
     * Tables that carry a team column the application does not own.
     *
     * The permission package scopes its own rows by team through its
     * registrar. Its column happens to share our name, but the mechanism is
     * separate, and wrapping it in our global scope would mean two things
     * filtering the same rows with no agreement on which wins.
     *
     * Listed as tables rather than models because two of them are pivots with
     * no model at all.
     *
     * @var array<int, string>
     */
    public const array UNSCOPED_TABLES = [
        'roles',
        'model_has_roles',
        'model_has_permissions',
    ];

    private ?Team $tenant = null;

    private bool $resolved = false;

    /**
     * Depth of nested withoutTenancy() calls. A counter rather than a flag so
     * an inner call cannot re-enable scoping for the outer one on its way out.
     */
    private int $suspended = 0;

    public function __construct(private readonly AuthFactory $auth) {}

    public function set(?Team $team): void
    {
        $this->tenant = $team;
        $this->resolved = true;

        $this->publishToContext();
    }

    public function current(): ?Team
    {
        if ($this->resolved) {
            return $this->tenant;
        }

        $this->resolved = true;

        $this->tenant = $this->auth->guard()->user()?->currentTeam;

        $this->publishToContext();

        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->current()?->id;
    }

    public function has(): bool
    {
        return $this->current() instanceof Team;
    }

    /**
     * Drop the cached answer so the next call resolves again.
     *
     * Needed whenever the signed in user changes mid-execution: logging in,
     * switching teams, or a job moving on to the next tenant.
     */
    public function forget(): void
    {
        $this->tenant = null;
        $this->resolved = false;

        $this->publishToContext();
    }

    public function isSuspended(): bool
    {
        return $this->suspended > 0;
    }

    /**
     * Run a callback with tenant scoping turned off.
     *
     * The deliberate escape hatch for work that legitimately spans tenants — a
     * nightly report, a migration, the operator panel. Being a callback rather
     * than a toggle is the point: scoping cannot be left off by accident,
     * because it comes back even when the callback throws.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function withoutTenancy(Closure $callback): mixed
    {
        $this->suspended++;

        try {
            return $callback();
        } finally {
            $this->suspended--;
        }
    }

    /**
     * Run a callback as if the given team were the current tenant.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function forTenant(?Team $team, Closure $callback): mixed
    {
        [$previousTenant, $previousResolved] = [$this->tenant, $this->resolved];

        $this->set($team);

        try {
            return $callback();
        } finally {
            $this->tenant = $previousTenant;
            $this->resolved = $previousResolved;

            $this->publishToContext();
        }
    }

    /**
     * Keeps the log context in step with whoever the current tenant is.
     *
     * Here rather than in a middleware because the tenant is not only set by
     * requests: a queued job restores it, the operator panel switches it, and
     * a nightly report moves through every team in turn. A middleware would
     * label the request correctly and leave every one of those lines saying
     * nothing about which customer they concern — which is exactly when the
     * label is worth having.
     *
     * Context is used rather than Log::withContext because it survives the
     * hand-off to the queue: the job's own lines carry the team and the
     * request that started it.
     */
    private function publishToContext(): void
    {
        Context::add('team_id', $this->tenant?->id);
    }
}
