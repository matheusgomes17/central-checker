<?php

declare(strict_types=1);

namespace Tests\Feature\Broadcasting;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Channel authorization is a tenant boundary.
 *
 * A team channel that lets any signed-in user in hands over in real time what
 * the global scope spent a whole phase keeping out of queries — and leaves no
 * trace in a query log, because there is no query.
 */
class ChannelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A suíte roda com o driver null, que devolve corpo vazio e nunca
        // exercitaria a autorização. Credenciais de mentira: nada sai pela
        // rede, só a resposta assinada que o cliente receberia.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);

        // Os canais são registrados no driver que estava ativo quando a
        // aplicação subiu. Trocar o driver depois dá um broadcaster novo e sem
        // canal nenhum — e aí tudo é negado, o que faria os testes de recusa
        // passarem pelo motivo errado. Re-registrar é o que os coloca no
        // driver que este teste vai exercitar.
        require base_path('routes/channels.php');

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_a_member_may_listen_to_their_team(): void
    {
        [$team, $member] = $this->teamWithMember();

        $this->assertTrue($this->authorize($member, 'teams.'.$team->id));
    }

    /**
     * The case that matters. Everything else here is scaffolding for it.
     */
    public function test_an_outsider_may_not_listen_to_a_team(): void
    {
        [$team] = $this->teamWithMember();

        $stranger = User::factory()->create();

        $this->assertFalse($this->authorize($stranger, 'teams.'.$team->id));
    }

    public function test_a_member_appears_in_the_presence_channel(): void
    {
        [$team, $member] = $this->teamWithMember();

        $result = $this->authorize($member, 'teams.'.$team->id.'.presence', presence: true);

        $this->assertIsArray($result);
        $this->assertSame(['id' => $member->id, 'name' => $member->name], $result);
    }

    public function test_an_outsider_is_kept_out_of_the_presence_channel(): void
    {
        [$team] = $this->teamWithMember();

        $this->assertFalse($this->authorize(User::factory()->create(), 'teams.'.$team->id.'.presence', presence: true));
    }

    public function test_a_channel_for_a_team_that_does_not_exist_is_refused(): void
    {
        $this->assertFalse($this->authorize(User::factory()->create(), 'teams.999999'));
    }

    public function test_a_user_may_only_listen_to_their_own_notifications(): void
    {
        [$mine, $theirs] = [User::factory()->create(), User::factory()->create()];

        $this->assertTrue($this->authorize($mine, 'App.Models.User.'.$mine->id));
        $this->assertFalse($this->authorize($mine, 'App.Models.User.'.$theirs->id));
    }

    /**
     * Hits the real broadcasting auth endpoint, which is what a client does.
     *
     * Calling the channel callback directly would test a closure; this tests
     * the door.
     */
    private function authorize(User $user, string $channel, bool $presence = false): mixed
    {
        $prefix = $presence ? 'presence-' : 'private-';

        $response = $this->actingAs($user)->postJson('/broadcasting/auth', [
            'channel_name' => $prefix.$channel,
            'socket_id' => '1234.5678',
        ]);

        if ($response->status() === 403) {
            return false;
        }

        $response->assertOk();

        // Canal de presença devolve os dados do membro em channel_data; canal
        // privado devolve só a assinatura.
        $data = $response->json('channel_data');

        if (! is_string($data)) {
            return true;
        }

        /** @var array{user_info?: array<string, mixed>} $decoded */
        $decoded = json_decode($data, true);

        return $decoded['user_info'] ?? true;
    }

    /**
     * @return array{Team, User}
     */
    private function teamWithMember(): array
    {
        $team = Team::factory()->create(['is_personal' => false]);
        $member = User::factory()->create();

        $team->memberships()->create(['user_id' => $member->id]);
        $member->assignTeamRole($team, TeamRole::Member);

        return [$team, $member];
    }
}
