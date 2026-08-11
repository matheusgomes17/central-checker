<?php

declare(strict_types=1);

namespace App\Support\Observability;

use App\Support\TenantManager;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Facades\Context;
use Sentry\State\Scope;

/**
 * Tells the alert which customer it is about.
 *
 * An exception report that says only what broke sends whoever is on call
 * looking through logs to find who it broke for. The phase is only met when
 * the alert itself carries the tenant.
 *
 * The user is identified by id and nothing else. Sending name and email to a
 * third party is a decision about data belonging to another person, not a default — which
 * is why send_default_pii stays off.
 */
class SentryContext
{
    public function __construct(
        private readonly TenantManager $tenants,
        private readonly AuthFactory $auth,
    ) {}

    public function applyTo(Scope $scope): void
    {
        $scope->setTag('team_id', (string) ($this->tenants->id() ?? 'none'));

        $requestId = Context::get('request_id');

        if (is_string($requestId)) {
            // A mesma etiqueta que está no log: com ela dá para ir do alerta
            // para as linhas daquela requisição sem procurar por horário.
            $scope->setTag('request_id', $requestId);
        }

        $userId = $this->auth->guard()->id();

        if ($userId !== null) {
            $scope->setUser(['id' => $userId]);
        }
    }
}
