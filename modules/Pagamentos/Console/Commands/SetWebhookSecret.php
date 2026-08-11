<?php

declare(strict_types=1);

namespace Modules\Pagamentos\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Modules\Pagamentos\Support\EnvFile;

/**
 * Writes VIRTUALPAY_WEBHOOK_SECRET straight into `.env`.
 *
 * The gateway's webhook secret is typed by a developer into the gateway's own
 * admin UI when the webhook is created — there's no API to fetch it back, so
 * this command exists purely so swapping it locally doesn't mean hand-editing
 * `.env` and risking a typo in the key name. Mirrors the practicality of
 * `key:generate`, which does the same kind of write.
 */
#[Signature('virtualpay:webhook-secret {secret : O segredo do webhook, o mesmo valor cadastrado no gateway}')]
#[Description('Grava VIRTUALPAY_WEBHOOK_SECRET no .env e limpa o cache de config')]
class SetWebhookSecret extends Command
{
    public function handle(): int
    {
        $path = app()->environmentFilePath();
        $contents = file_get_contents($path);

        $new = EnvFile::withValue(
            $contents === false ? '' : $contents,
            'VIRTUALPAY_WEBHOOK_SECRET',
            (string) $this->argument('secret'),
        );

        file_put_contents($path, $new);

        $this->call('config:clear');

        $this->components->info('Segredo do webhook VirtualPay atualizado.');

        return self::SUCCESS;
    }
}
