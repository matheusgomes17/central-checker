<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use App\Enums\TeamRole;
use App\Http\Middleware\AddRequestContext;
use App\Models\Team;
use App\Models\User;
use App\Support\TenantManager;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Tests\TestCase;

/**
 * Reading production logs means finding the lines that belong together. With
 * concurrent traffic that is impossible without an identifier, and it gets
 * harder exactly when volume is high — which is when the incident usually is.
 */
class RequestContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_request_gets_an_identifier(): void
    {
        $response = $this->get('/login')->assertOk();

        $this->assertNotEmpty($response->headers->get(AddRequestContext::HEADER));
    }

    /**
     * A trace started by a load balancer or by the caller should carry
     * through, not be renamed at our door.
     */
    public function test_an_incoming_identifier_is_kept(): void
    {
        $response = $this->withHeader(AddRequestContext::HEADER, 'trace-de-fora')->get('/login');

        $this->assertSame('trace-de-fora', $response->headers->get(AddRequestContext::HEADER));
    }

    /**
     * The header comes from outside and lands on every log line, so a caller
     * must not be able to write a megabyte per line into our logs.
     */
    public function test_an_oversized_identifier_is_cut(): void
    {
        $response = $this->withHeader(AddRequestContext::HEADER, str_repeat('a', 5000))->get('/login');

        $header = $response->headers->get(AddRequestContext::HEADER);

        $this->assertIsString($header);
        $this->assertSame(64, mb_strlen($header));
    }

    public function test_a_blank_identifier_is_replaced(): void
    {
        $response = $this->withHeader(AddRequestContext::HEADER, '   ')->get('/login');

        $header = $response->headers->get(AddRequestContext::HEADER);

        $this->assertIsString($header);
        $this->assertNotSame('   ', $header);
        $this->assertNotEmpty(trim($header));
    }

    /**
     * The point of the identifier: the lines a request wrote can be found
     * afterwards without guessing by timestamp.
     */
    public function test_the_identifier_reaches_the_written_log_line(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'log');

        $this->assertIsString($path);

        // Um canal de verdade, com o mesmo formatador da produção: o evento
        // MessageLogged não serve aqui, porque o contexto é acrescentado
        // depois dele, por um processador do Monolog.
        Config::set('logging.default', 'probe');
        Config::set('logging.channels.probe', [
            'driver' => 'monolog',
            'handler' => StreamHandler::class,
            'handler_with' => ['stream' => $path],
            'formatter' => JsonFormatter::class,
        ]);

        Route::middleware('web')->get('/_contexto', function (): string {
            Log::info('linha de teste');

            return 'ok';
        });

        $this->withHeader(AddRequestContext::HEADER, 'trace-a')->get('/_contexto')->assertOk();

        $written = trim((string) file_get_contents($path));
        unlink($path);

        // Uma linha só: um registro que quebrasse linha viraria vários eventos
        // truncados no coletor.
        $this->assertCount(1, explode(PHP_EOL, $written));

        /** @var array{message: string, extra: array<string, mixed>} $record */
        $record = json_decode($written, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('linha de teste', $record['message']);
        $this->assertSame('trace-a', $record['extra']['request_id'] ?? null);
    }

    /**
     * The pair is the point: which request, and which customer it was for. A
     * line with only one of the two still leaves the question open.
     */
    public function test_a_written_line_carries_both_the_request_and_the_team(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $team = Team::factory()->create(['is_personal' => false]);
        $user = User::factory()->create();

        $team->memberships()->create(['user_id' => $user->id]);
        $user->assignTeamRole($team, TeamRole::Owner);
        $user->switchTeam($team);

        $path = tempnam(sys_get_temp_dir(), 'log');
        $this->assertIsString($path);

        Config::set('logging.default', 'probe');
        Config::set('logging.channels.probe', [
            'driver' => 'monolog',
            'handler' => StreamHandler::class,
            'handler_with' => ['stream' => $path],
            'formatter' => JsonFormatter::class,
        ]);

        Route::middleware('web')->get('/_contexto-do-time', function (): string {
            resolve(TenantManager::class)->current();

            Log::info('linha de teste');

            return 'ok';
        });

        $this->actingAs($user)
            ->withHeader(AddRequestContext::HEADER, 'trace-b')
            ->get('/_contexto-do-time')
            ->assertOk();

        $written = trim((string) file_get_contents($path));
        unlink($path);

        /** @var array{extra: array<string, mixed>} $record */
        $record = json_decode($written, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('trace-b', $record['extra']['request_id'] ?? null);
        $this->assertSame($team->id, $record['extra']['team_id'] ?? null);
    }

    public function test_the_team_travels_in_the_context(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $team = Team::factory()->create(['is_personal' => false]);
        $user = User::factory()->create();

        $team->memberships()->create(['user_id' => $user->id]);
        $user->assignTeamRole($team, TeamRole::Owner);

        resolve(TenantManager::class)->set($team);

        $this->assertSame($team->id, Context::get('team_id'));
    }

    /**
     * The tenant is not only set by requests — a queued job restores it and a
     * nightly report moves through every team in turn. The label has to follow
     * whoever is current, or those lines say nothing about which customer they
     * concern.
     */
    public function test_the_context_follows_a_scoped_callback(): void
    {
        $teamA = Team::factory()->create(['is_personal' => false]);
        $teamB = Team::factory()->create(['is_personal' => false]);

        $tenants = resolve(TenantManager::class);

        $tenants->set($teamA);

        $tenants->forTenant($teamB, function () use ($teamB): void {
            $this->assertSame($teamB->id, Context::get('team_id'));
        });

        $this->assertSame($teamA->id, Context::get('team_id'));
    }

    public function test_forgetting_the_tenant_clears_the_label(): void
    {
        $team = Team::factory()->create(['is_personal' => false]);

        $tenants = resolve(TenantManager::class);

        $tenants->set($team);
        $tenants->forget();

        $this->assertNull(Context::get('team_id'));
    }
}
