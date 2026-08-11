<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use App\Listeners\CheckApplicationHealth;
use Illuminate\Contracts\Filesystem\Factory as StorageFactory;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\Health\BrokenQueue;
use Tests\Support\Health\BrokenRedis;
use Tests\Support\Health\BrokenResolver;
use Tests\Support\Health\BrokenStorage;
use Tests\Support\Health\SilentlyLosingDisk;
use Tests\TestCase;

/**
 * The built-in /up only reports that PHP booted, which is the least useful
 * thing to know: an instance with no database still boots, still answers 200,
 * and still gets traffic pointed at it by the load balancer.
 */
class HealthCheckTest extends TestCase
{
    public function test_it_passes_when_every_dependency_answers(): void
    {
        $this->expectNotToPerformAssertions();

        $this->listener()->handle(new DiagnosingHealth);
    }

    public function test_it_fails_when_the_database_is_gone(): void
    {
        $this->expectException(RuntimeException::class);
        // A dependência aparece na mensagem: um /up que só diz "não" manda
        // quem está de plantão abrir o servidor para descobrir qual das
        // quatro caiu.
        $this->expectExceptionMessageMatches('/database/');

        $this->listener(database: new BrokenResolver)->handle(new DiagnosingHealth);
    }

    public function test_it_fails_when_redis_is_gone(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/redis/');

        $this->listener(redis: new BrokenRedis)->handle(new DiagnosingHealth);
    }

    /**
     * A Redis that is up answers ping while the queue behind it is stuck, and
     * a stuck queue is holding email, webhooks and billing.
     */
    public function test_it_fails_when_the_queue_cannot_be_read(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/queue/');

        $this->listener(queue: new BrokenQueue)->handle(new DiagnosingHealth);
    }

    public function test_it_fails_when_storage_cannot_be_reached(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/storage/');

        $this->listener(storage: new BrokenStorage)->handle(new DiagnosingHealth);
    }

    /**
     * Listing files works fine on a disk mounted read-only and on a full one,
     * so the probe writes and reads back instead of trusting the write.
     */
    public function test_it_fails_when_storage_reads_back_something_else(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/storage/');

        $this->listener(storage: new SilentlyLosingDisk)->handle(new DiagnosingHealth);
    }

    public function test_the_original_message_is_kept_for_whoever_is_on_call(): void
    {
        try {
            $this->listener(database: new BrokenResolver)->handle(new DiagnosingHealth);
            $this->fail('deveria ter lançado');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString(BrokenResolver::REASON, $e->getMessage());
            $this->assertInstanceOf(RuntimeException::class, $e->getPrevious());
        }
    }

    private function listener(
        ?ConnectionResolverInterface $database = null,
        ?RedisFactory $redis = null,
        ?QueueFactory $queue = null,
        ?StorageFactory $storage = null,
    ): CheckApplicationHealth {
        Storage::fake();

        return new CheckApplicationHealth(
            $database ?? resolve(ConnectionResolverInterface::class),
            $redis ?? resolve(RedisFactory::class),
            $queue ?? resolve(QueueFactory::class),
            $storage ?? resolve(StorageFactory::class),
        );
    }
}
