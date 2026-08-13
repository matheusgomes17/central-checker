<?php

declare(strict_types=1);

namespace Modules\Payments\Providers;

use Illuminate\Support\Facades\Config;
use Modules\Core\Providers\ModuleServiceProvider;
use Modules\Payments\Console\Commands\SetWebhookSecret;
use Modules\Payments\Contracts\PaymentGateway;
use Modules\Payments\Contracts\WebhookVerifier;
use Modules\Payments\Gateways\VirtualPayGateway;
use Modules\Payments\Webhooks\VirtualPayWebhookVerifier;
use Override;

class PaymentsServiceProvider extends ModuleServiceProvider
{
    protected function name(): string
    {
        return 'Payments';
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

    // Comandos em modules/*/Console/Commands não são descobertos
    // automaticamente (mesma situação de MakeModuleCommand em Core);
    // precisam de registro explícito.
    #[Override]
    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([SetWebhookSecret::class]);
        }
    }
}
