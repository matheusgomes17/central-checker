<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Contracts\Filesystem\Factory as StorageFactory;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Foundation\Events\DiagnosingHealth;
use RuntimeException;
use Throwable;

/**
 * Answers what /up is actually being asked.
 *
 * The built-in route only reports that PHP booted, which is the least useful
 * thing to know: an instance with no database still boots, still returns 200,
 * and still gets traffic sent to it by the load balancer. Every dependency
 * checked here is one the application cannot serve a request without.
 *
 * Throwing is the contract — the framework turns any exception from this
 * listener into a 500, which is what an uptime monitor reads.
 */
class CheckApplicationHealth
{
    /**
     * The four are contracts rather than the concrete managers so a test can
     * stand in for one of them without pulling a mocking library in for the
     * only place in the suite that would need it.
     */
    public function __construct(
        private readonly ConnectionResolverInterface $database,
        private readonly RedisFactory $redis,
        private readonly QueueFactory $queue,
        private readonly StorageFactory $storage,
    ) {}

    public function handle(DiagnosingHealth $event): void
    {
        $this->check('database', fn () => $this->database->connection()->select('select 1'));

        $this->check('redis', fn () => $this->redis->connection()->command('ping'));

        // O tamanho da fila, não só a conexão: um Redis no ar com a fila
        // parada devolve ping normalmente, e é a fila parada que segura
        // e-mail, webhook e cobrança.
        $this->check('queue', fn () => $this->queue->connection()->size());

        // Escrita e leitura, não apenas listagem: um disco cheio ou montado
        // como somente leitura lista arquivos sem reclamar.
        $this->check('storage', function (): void {
            $disk = $this->storage->disk();
            $probe = 'health/probe.txt';

            $disk->put($probe, 'ok');

            if ($disk->get($probe) !== 'ok') {
                throw new RuntimeException('leitura devolveu conteúdo diferente do escrito');
            }

            $disk->delete($probe);
        });
    }

    /**
     * @param  callable(): mixed  $probe
     */
    private function check(string $name, callable $probe): void
    {
        try {
            $probe();
        } catch (Throwable $e) {
            // A mensagem original entra na exceção: um /up que só diz "não"
            // manda quem está de plantão abrir o servidor para descobrir qual
            // das quatro dependências caiu.
            throw new RuntimeException("health check falhou em {$name}: {$e->getMessage()}", $e->getCode(), previous: $e);
        }
    }
}
