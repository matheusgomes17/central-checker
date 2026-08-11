<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Console\ServeCommand;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * `artisan serve` copies only a fixed list of variables into the process that
 * answers requests; everything else that process re-reads from the .env file.
 * The .env points at 127.0.0.1, which is right on the host and wrong inside the
 * container, so an override the Compose file adds without a matching entry here
 * reaches the container's CLI and not its HTTP.
 *
 * That failure looks like a networking problem and is not one, which is why it
 * gets a test instead of a paragraph.
 */
class DevServerEnvironmentTest extends TestCase
{
    /**
     * Read by the container entrypoint to match the host user's id, never by
     * the application. Nothing to pass through.
     */
    private const array NOT_READ_BY_THE_APPLICATION = [
        'WWWUSER',
    ];

    public function test_every_variable_the_container_overrides_reaches_the_dev_server(): void
    {
        $overridden = array_diff(
            array_keys($this->containerEnvironment()),
            self::NOT_READ_BY_THE_APPLICATION,
        );

        $this->assertNotEmpty($overridden, 'O compose.yaml deixou de sobrescrever qualquer variável.');

        foreach ($overridden as $variable) {
            $this->assertContains(
                $variable,
                ServeCommand::$passthroughVariables,
                "O compose.yaml sobrescreve [{$variable}] para o contêiner, mas o servidor de desenvolvimento não recebe essa variável — dentro do contêiner a CLI enxerga um valor e o HTTP enxerga outro.",
            );
        }
    }

    public function test_the_declared_list_matches_what_the_container_overrides(): void
    {
        $overridden = array_values(array_diff(
            array_keys($this->containerEnvironment()),
            self::NOT_READ_BY_THE_APPLICATION,
            // O que o próprio Laravel já repassa não precisa ser declarado.
            ServeCommand::$passthroughVariables === []
                ? []
                : array_diff(ServeCommand::$passthroughVariables, AppServiceProvider::CONTAINER_SERVICE_VARIABLES),
        ));

        sort($overridden);

        $declared = AppServiceProvider::CONTAINER_SERVICE_VARIABLES;
        sort($declared);

        $this->assertSame(
            $overridden,
            $declared,
            'A constante do AppServiceProvider e o bloco environment do compose.yaml divergiram.',
        );
    }

    /**
     * The environment block the Compose file sets for the application service.
     *
     * @return array<string, string>
     */
    private function containerEnvironment(): array
    {
        /** @var array{services: array{'laravel.test': array{environment: array<string, string>}}} $compose */
        $compose = Yaml::parseFile(base_path('compose.yaml'));

        return $compose['services']['laravel.test']['environment'];
    }
}
