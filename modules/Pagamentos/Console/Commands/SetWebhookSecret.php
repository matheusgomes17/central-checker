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
        $exists = file_exists($path);
        $contents = $exists ? file_get_contents($path) : '';

        // Um .env que existe mas não pôde ser lido não pode virar '': isso
        // truncaria o arquivo para conter só a linha do webhook na escrita
        // abaixo, apagando toda credencial já configurada.
        if ($exists && $contents === false) {
            $this->components->error('Não foi possível ler o .env em '.$path.'.');

            return self::FAILURE;
        }

        $new = EnvFile::withValue(
            $contents === false ? '' : $contents,
            'VIRTUALPAY_WEBHOOK_SECRET',
            (string) $this->argument('secret'),
        );

        if (file_put_contents($path, $new) === false) {
            $this->components->error('Não foi possível gravar o .env.');

            return self::FAILURE;
        }

        $this->call('config:clear');

        $this->components->info('Segredo do webhook VirtualPay atualizado.');

        return self::SUCCESS;
    }
}
