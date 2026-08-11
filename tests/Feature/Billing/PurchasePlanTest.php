<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Actions\Billing\PurchasePlan;
use App\Models\PlanSubscription;
use App\Models\Team;
use App\Support\Billing\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Modules\Pagamentos\Contracts\PaymentGateway;
use Modules\Pagamentos\Enums\PaymentPurpose;
use Modules\Pagamentos\Enums\PaymentStatus;
use Modules\Pagamentos\Gateways\VirtualPayGateway;
use Modules\Pagamentos\Support\PayerData;
use Tests\TestCase;

/**
 * PurchasePlan only opens the Pix charge for a plan purchase. Activating the
 * subscription is the webhook's job (Task 6) once the payment is confirmed —
 * these tests pin down that no PlanSubscription is ever created here.
 */
class PurchasePlanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // O binding real (Task 8) resolve com credenciais de config (string
        // vazia em teste); este teste não exercita as credenciais, só precisa
        // de uma instância concreta para a action resolver via Http::fake().
        app()->bind(PaymentGateway::class, fn (): VirtualPayGateway => new VirtualPayGateway('https://api.virtualpay.online/v2', 'cid', 'csecret'));
    }

    public function test_purchasing_a_valid_plan_creates_a_pending_intent_with_the_gateway_data(): void
    {
        Http::fake(['*/transactions' => Http::response([
            'id' => 'uuid-1', 'copy_paste' => '000201', 'qr_code' => 'b64', 'status' => 'pending',
        ], 201)]);

        $team = Team::factory()->create();
        $plan = Plan::find('pro');

        $intent = resolve(PurchasePlan::class)->handle($team, $plan, PayerData::for('Fulano', 'f@e.com'));

        $this->assertSame(PaymentStatus::Pending, $intent->status);
        $this->assertSame(PaymentPurpose::Plan, $intent->purpose);
        $this->assertSame('pro', $intent->plan);
        $this->assertSame($plan->price, $intent->amount_cents);
        $this->assertSame('uuid-1', $intent->external_id);
        $this->assertSame('000201', $intent->copy_paste);
        $this->assertSame('b64', $intent->qr_code);
        $this->assertStringStartsWith('PLAN-pro-'.$team->getKey().'-', $intent->reference);

        $this->assertSame(0, PlanSubscription::query()->count());
    }

    public function test_purchasing_a_free_plan_is_refused_before_anything_is_sent(): void
    {
        Http::fake();

        $team = Team::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        try {
            resolve(PurchasePlan::class)->handle($team, Plan::find('free'), PayerData::for('Fulano', 'f@e.com'));
        } finally {
            Http::assertNothingSent();
        }
    }
}
