<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The acceptance criterion of the DX phase is someone new getting the project
 * running on their own, and `bin/setup` is the whole of that promise.
 *
 * What breaks it is not prose: it is the order of two commands, a dependency
 * the script forgets to check, and a closing message that states ports the
 * machine is not using. Each assertion below stands for a failure that
 * happened on a real machine.
 */
class SetupScriptTest extends TestCase
{
    public function test_php_dependencies_are_installed_before_the_containers_start(): void
    {
        // O Compose monta um arquivo de dentro de vendor/. Num clone limpo,
        // subir os serviços antes do composer install faz o Docker criar esse
        // caminho como diretório do root — e o composer install seguinte morre
        // sem permissão de escrever em vendor/. Quem cai nisso precisa de um
        // contêiner só para apagar o que o Docker deixou.
        $this->assertStringContainsString(
            './vendor/',
            (string) file_get_contents(base_path('compose.yaml')),
            'O compose.yaml não lê mais de vendor/; se isso for verdade, esta ordem deixou de importar.',
        );

        $script = $this->script();

        $install = mb_strpos($script, 'composer install');
        $up = mb_strpos($script, 'docker compose up -d');

        $this->assertNotFalse($install, 'bin/setup não instala mais as dependências PHP.');
        $this->assertNotFalse($up, 'bin/setup não sobe mais os serviços.');

        $this->assertLessThan(
            $up,
            $install,
            'bin/setup precisa rodar `composer install` antes de `docker compose up -d`: o Compose monta um arquivo de vendor/, e num clone limpo o Docker cria esse caminho como root.',
        );
    }

    public function test_it_requires_aspell_because_composer_check_ends_in_peck(): void
    {
        /** @var array{scripts: array<string, list<string>>} $composer */
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);

        $this->assertContains(
            '@spell:check',
            $composer['scripts']['check'],
            'O `composer check` não chama mais o Peck; sem isso o aspell deixa de ser pré-requisito.',
        );

        $this->assertStringContainsString(
            'require aspell',
            $this->script(),
            'bin/setup não confere o aspell — e sem ele o `composer check` aborta no Peck antes de chegar aos testes.',
        );
    }

    public function test_the_closing_message_honours_the_forward_port_overrides(): void
    {
        // Porta fixa na mensagem final é pior que porta nenhuma: quem remapeou
        // por conflito com outro projeto recebe um endereço que não responde e
        // vai procurar o erro no contêiner, que está certo.
        $closing = $this->closingMessage();

        $overridable = $this->overridablePortDefaults();

        preg_match_all('/:(\d{2,5})\b/', $closing, $matches);

        foreach ($matches[1] as $port) {
            $variable = array_search($port, $overridable, strict: true);

            $this->assertFalse(
                $variable,
                "A mensagem final de bin/setup fixa a porta {$port}, que o compose.yaml deixa trocar por {$variable}.",
            );
        }

        // Endereço sem porta é a mesma armadilha em outra forma: vale 80, que é
        // justamente o que colide com qualquer outro projeto na máquina.
        $this->assertDoesNotMatchRegularExpression(
            '#https?://localhost(?![:0-9])#',
            $closing,
            'A mensagem final aponta para http://localhost sem porta, o que só está certo enquanto APP_PORT for 80.',
        );
    }

    /**
     * @return array<string, string> variable name => default port
     */
    private function overridablePortDefaults(): array
    {
        preg_match_all(
            '/\$\{(APP_PORT|VITE_PORT|FORWARD_[A-Z_]+):-(\d+)\}/',
            (string) file_get_contents(base_path('compose.yaml')),
            $matches,
            PREG_SET_ORDER,
        );

        $this->assertNotEmpty($matches, 'O compose.yaml não expõe mais porta configurável.');

        $defaults = [];

        foreach ($matches as $match) {
            $defaults[$match[1]] = $match[2];
        }

        return $defaults;
    }

    private function closingMessage(): string
    {
        $script = $this->script();
        $start = mb_strpos($script, "step 'Pronto'");

        $this->assertNotFalse($start, 'bin/setup não tem mais a etapa final que imprime os endereços.');

        return mb_substr($script, $start);
    }

    private function script(): string
    {
        return (string) file_get_contents(base_path('bin/setup'));
    }
}
