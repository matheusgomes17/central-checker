<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\SystemRole;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\Billing\TeamBilling;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Fills the database with something worth looking at.
 *
 * An empty application hides every screen that matters: no team switcher, no
 * member list, no billing state, no pending invitation. This seeds the states
 * someone needs to see on the first day — including the awkward ones, because
 * a demo where every subscription is healthy teaches nothing about what the
 * application does when one is not.
 *
 * Everything here is written as an upsert. `bin/setup` runs it, `bin/setup`
 * has to be safe to run twice, and a demo seeder that duplicates its own teams
 * on the second run is a seeder people stop running.
 *
 * Emails use the .test domain, which is reserved and cannot be delivered to.
 * Sample data with a real address in it eventually mails a stranger.
 */
class DemoSeeder extends Seeder
{
    private const string PASSWORD = 'password';

    /**
     * @var list<array{slug: string, name: string, status: string, price: string, plan: string}>
     */
    private const array TEAMS = [
        ['slug' => 'acme', 'name' => 'Acme', 'status' => 'active', 'price' => 'business', 'plan' => 'Business'],
        ['slug' => 'globex', 'name' => 'Globex', 'status' => 'trialing', 'price' => 'pro', 'plan' => 'Pro em teste'],
        ['slug' => 'initech', 'name' => 'Initech', 'status' => 'past_due', 'price' => 'pro', 'plan' => 'Pro inadimplente'],
        ['slug' => 'hooli', 'name' => 'Hooli', 'status' => 'none', 'price' => 'free', 'plan' => 'Gratuito acima do teto'],
    ];

    /**
     * @var list<array{name: string, email: string, teams: array<string, TeamRole>}>
     */
    private const array PEOPLE = [
        ['name' => 'Ana Ribeiro', 'email' => 'ana@example.test', 'teams' => ['acme' => TeamRole::Owner]],
        ['name' => 'Bruno Sales', 'email' => 'bruno@example.test', 'teams' => ['globex' => TeamRole::Owner]],
        ['name' => 'Carla Nunes', 'email' => 'carla@example.test', 'teams' => ['initech' => TeamRole::Owner]],
        ['name' => 'Diego Prado', 'email' => 'diego@example.test', 'teams' => ['acme' => TeamRole::Admin]],
        ['name' => 'Elisa Fontes', 'email' => 'elisa@example.test', 'teams' => ['acme' => TeamRole::Member]],
        ['name' => 'Felipe Braga', 'email' => 'felipe@example.test', 'teams' => ['acme' => TeamRole::Billing]],
        ['name' => 'Gabi Torres', 'email' => 'gabi@example.test', 'teams' => ['globex' => TeamRole::Member]],
        ['name' => 'Henrique Lima', 'email' => 'henrique@example.test', 'teams' => ['globex' => TeamRole::Admin]],
        ['name' => 'Iara Campos', 'email' => 'iara@example.test', 'teams' => ['initech' => TeamRole::Member]],
        // Em dois times de propósito: é quem faz o seletor de time ter o que
        // selecionar, e o único jeito de ver na tela que papel é por time.
        ['name' => 'João Vidal', 'email' => 'joao@example.test', 'teams' => ['acme' => TeamRole::Member, 'globex' => TeamRole::Admin]],
        // Hooli não tem assinatura e cai no plano gratuito, cujo teto de
        // membros é três. Quatro pessoas aqui deixam o time visivelmente
        // acima do teto, que é o estado que esta tarefa torna visível.
        ['name' => 'Karen Duarte', 'email' => 'karen@example.test', 'teams' => ['hooli' => TeamRole::Owner]],
        ['name' => 'Leandro Matos', 'email' => 'leandro@example.test', 'teams' => ['hooli' => TeamRole::Member]],
        ['name' => 'Marcia Souza', 'email' => 'marcia@example.test', 'teams' => ['hooli' => TeamRole::Member]],
        ['name' => 'Nelson Vaz', 'email' => 'nelson@example.test', 'teams' => ['hooli' => TeamRole::Member]],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('O DemoSeeder cria usuários com senha conhecida e não roda em produção.');
        }

        $teams = $this->teams();

        $this->people($teams);
        $this->subscriptions($teams);
        $this->pendingInvitations($teams);

        $this->report();
    }

    /**
     * @return array<string, Team>
     */
    private function teams(): array
    {
        $teams = [];

        foreach (self::TEAMS as $definition) {
            $teams[$definition['slug']] = Team::withoutGlobalScopes()->firstOrCreate(
                ['slug' => $definition['slug']],
                ['name' => $definition['name'], 'is_personal' => false, 'stripe_id' => 'cus_demo_'.$definition['slug']],
            );
        }

        return $teams;
    }

    /**
     * @param  array<string, Team>  $teams
     */
    private function people(array $teams): void
    {
        foreach (self::PEOPLE as $person) {
            $user = User::query()->where('email', $person['email'])->first();

            // A factory é quem cria o time pessoal e define o time atual. Fazer
            // isso à mão aqui seria duplicar a regra em dois lugares.
            $user ??= User::factory()->create([
                'name' => $person['name'],
                'email' => $person['email'],
                'password' => bcrypt(self::PASSWORD),
            ]);

            foreach ($person['teams'] as $slug => $role) {
                $team = $teams[$slug];

                if (! $user->belongsToTeam($team)) {
                    $team->members()->attach($user);
                }

                $user->assignTeamRole($team, $role);
            }
        }

        // Ana administra a plataforma, não só o time dela: sem isso o /admin
        // fica inacessível e metade do que a fase 4 construiu não aparece.
        $ana = User::query()->where('email', 'ana@example.test')->firstOrFail();
        $ana->setSystemRole(SystemRole::SuperAdmin, true);
    }

    /**
     * @param  array<string, Team>  $teams
     */
    private function subscriptions(array $teams): void
    {
        foreach (self::TEAMS as $definition) {
            // Time sem assinatura existe de propósito na demonstração: é o que
            // mostra a tela de excedente, que só aparece no plano gratuito.
            if ($definition['status'] === 'none') {
                continue;
            }

            $teams[$definition['slug']]->subscriptions()->updateOrCreate(
                ['type' => TeamBilling::SUBSCRIPTION],
                [
                    'stripe_id' => 'sub_demo_'.$definition['slug'],
                    'stripe_status' => $definition['status'],
                    'stripe_price' => $definition['price'],
                    'quantity' => 1,
                    'trial_ends_at' => $definition['status'] === 'trialing' ? now()->addDays(9) : null,
                    'ends_at' => null,
                ],
            );
        }
    }

    /**
     * @param  array<string, Team>  $teams
     */
    private function pendingInvitations(array $teams): void
    {
        $ana = User::query()->where('email', 'ana@example.test')->firstOrFail();

        foreach (['kelly@example.test', 'lucas@example.test'] as $email) {
            TeamInvitation::query()->firstOrCreate(
                ['team_id' => $teams['acme']->id, 'email' => $email],
                ['role' => TeamRole::Member, 'invited_by' => $ana->id, 'expires_at' => now()->addDays(7)],
            );
        }
    }

    private function report(): void
    {
        // O framework anota a propriedade como não nula, mas ela é sem tipo e
        // fica em null quando o seeder é chamado fora de um comando.
        if ($this->command === null) {
            return;
        }

        $this->command->info('Dados de demonstração prontos.');
        $this->command->table(
            ['Entre como', 'Senha', 'O que tem de interessante'],
            [
                ['ana@example.test', self::PASSWORD, 'Super admin, dona da Acme, /admin liberado'],
                ['bruno@example.test', self::PASSWORD, 'Globex em período de teste'],
                ['carla@example.test', self::PASSWORD, 'Initech inadimplente — time suspenso'],
                ['joao@example.test', self::PASSWORD, 'Em dois times, com papel diferente em cada'],
                ['karen@example.test', self::PASSWORD, 'Hooli sem assinatura, acima do teto do plano gratuito'],
            ],
        );
    }
}
