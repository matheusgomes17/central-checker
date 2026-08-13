<?php

declare(strict_types=1);

use Modules\Payments\Support\EnvFile;

it('substitui uma chave existente preservando as outras linhas', function (): void {
    $contents = "APP_NAME=Central\nVIRTUALPAY_WEBHOOK_SECRET=old\nAPP_DEBUG=true\n";

    $result = EnvFile::withValue($contents, 'VIRTUALPAY_WEBHOOK_SECRET', 'novo-segredo');

    expect($result)->toBe("APP_NAME=Central\nVIRTUALPAY_WEBHOOK_SECRET=novo-segredo\nAPP_DEBUG=true\n");
});

it('acrescenta a chave quando ela ainda não existe', function (): void {
    $contents = "APP_NAME=Central\nAPP_DEBUG=true\n";

    $result = EnvFile::withValue($contents, 'VIRTUALPAY_WEBHOOK_SECRET', 'novo-segredo');

    expect($result)->toBe("APP_NAME=Central\nAPP_DEBUG=true\nVIRTUALPAY_WEBHOOK_SECRET=novo-segredo\n");
});

it('coloca entre aspas um valor com espaço', function (): void {
    $result = EnvFile::withValue('', 'APP_NAME', 'Central Checker');

    expect($result)->toBe("APP_NAME=\"Central Checker\"\n");
});

it('não altera chaves não relacionadas', function (): void {
    $contents = "GOOGLE_CLIENT_ID=abc\nGOOGLE_CLIENT_SECRET=def\n";

    $result = EnvFile::withValue($contents, 'GOOGLE_CLIENT_ID', 'novo');

    expect($result)->toContain('GOOGLE_CLIENT_SECRET=def');
});

it('substitui apenas a chave pretendida, não uma chave que é prefixo de outra', function (): void {
    $contents = "FOO=um\nFOO_BAR=dois\n";

    $result = EnvFile::withValue($contents, 'FOO', 'novo');

    expect($result)->toBe("FOO=novo\nFOO_BAR=dois\n");
});
