<?php

declare(strict_types=1);

namespace Modules\Pagamentos\Providers;

use Illuminate\Support\Facades\Config;
use Modules\Core\Providers\ModuleServiceProvider;
use Modules\Pagamentos\Contracts\PaymentGateway;
use Modules\Pagamentos\Contracts\WebhookVerifier;
use Modules\Pagamentos\Gateways\VirtualPayGateway;
use Modules\Pagamentos\Webhooks\VirtualPayWebhookVerifier;
use Override;

class PagamentosServiceProvider extends ModuleServiceProvider
{
    protected function name(): string
    {
        return 'Pagamentos';
    }

    #[Override]
    protected function registerModule(): void
    {
        $this->app->singleton(WebhookVerifier::class, fn (): VirtualPayWebhookVerifier => new VirtualPayWebhookVerifier(
            Config::string('services.virtualpay.webhook_secret'),
        ));

        $this->app->singleton(PaymentGateway::class, fn (): VirtualPayGateway => new VirtualPayGateway(
            Config::string('services.virtualpay.base_url'),
            Config::string('services.virtualpay.client_id'),
            Config::string('services.virtualpay.client_secret'),
        ));
    }
}
