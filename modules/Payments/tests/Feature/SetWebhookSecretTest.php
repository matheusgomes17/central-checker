<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

// Usa a facade Artisan e app() em vez de $this->artisan()/$this->app: dentro de
// closures it()/test() do Pest, $this é tipado pelo stub do próprio Pest como
// TestCall, cujo @mixin aponta para PHPUnit\Framework\TestCase — sem os
// métodos que só existem no TestCase do Laravel. A facade evita a ambiguidade.
it('grava o novo segredo no .env preservando as outras linhas', function (): void {
    $tmpDir = sys_get_temp_dir().'/pagamentos-env-'.uniqid();
    mkdir($tmpDir);

    file_put_contents($tmpDir.'/.env', "APP_NAME=Central\nVIRTUALPAY_WEBHOOK_SECRET=old\nAPP_DEBUG=true\n");

    app()->useEnvironmentPath($tmpDir);

    try {
        expect(Artisan::call('virtualpay:webhook-secret', ['secret' => 'novo-segredo']))->toBe(0);

        $contents = (string) file_get_contents($tmpDir.'/.env');

        expect($contents)->toContain('VIRTUALPAY_WEBHOOK_SECRET=novo-segredo');
        expect($contents)->not->toContain('VIRTUALPAY_WEBHOOK_SECRET=old');
        expect($contents)->toContain('APP_NAME=Central');
        expect($contents)->toContain('APP_DEBUG=true');
    } finally {
        unlink($tmpDir.'/.env');
        rmdir($tmpDir);
    }
});

it('cria o .env quando ele ainda não existe', function (): void {
    $tmpDir = sys_get_temp_dir().'/pagamentos-env-'.uniqid();
    mkdir($tmpDir);

    app()->useEnvironmentPath($tmpDir);

    try {
        expect(file_exists($tmpDir.'/.env'))->toBeFalse();

        expect(Artisan::call('virtualpay:webhook-secret', ['secret' => 'novo-segredo']))->toBe(0);

        $contents = (string) file_get_contents($tmpDir.'/.env');

        expect($contents)->toContain('VIRTUALPAY_WEBHOOK_SECRET=novo-segredo');
    } finally {
        unlink($tmpDir.'/.env');
        rmdir($tmpDir);
    }
});
