<?php

declare(strict_types=1);

namespace Modules\Core\Providers;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Modules\Core\Console\Commands\MakeModuleCommand;
use Modules\Core\Support\Modules;
use Override;

/**
 * Bootstraps the module system itself: resolves the enabled modules, registers
 * their providers and teaches Laravel how to find factories and policies that
 * live inside a module instead of under app/.
 */
class CoreServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->singleton(Modules::class);

        foreach ($this->app->make(Modules::class)->providers() as $provider) {
            $this->app->register($provider);
        }
    }

    public function boot(): void
    {
        $this->resolveModuleFactories();
        $this->resolveModulePolicies();

        if ($this->app->runningInConsole()) {
            $this->commands([MakeModuleCommand::class]);
        }
    }

    /**
     * Map Modules\Foo\Models\Bar to Modules\Foo\Database\Factories\BarFactory
     * and back, falling back to the application's own conventions.
     */
    private function resolveModuleFactories(): void
    {
        Factory::guessFactoryNamesUsing(
            /**
             * @param  class-string<Model>  $model
             * @return class-string<Factory<Model>>
             */
            function (string $model): string {
                $factory = str_starts_with($model, 'Modules\\') && str_contains($model, '\\Models\\')
                    ? Str::replaceFirst('\\Models\\', '\\Database\\Factories\\', $model).'Factory'
                    : 'Database\\Factories\\'.class_basename($model).'Factory';

                /** @var class-string<Factory<Model>> $factory */
                return $factory;
            }
        );

        Factory::guessModelNamesUsing(
            /**
             * @param  Factory<Model>  $factory
             * @return class-string<Model>
             */
            function (Factory $factory): string {
                $class = $factory::class;

                $model = str_starts_with($class, 'Modules\\') && str_contains($class, '\\Database\\Factories\\')
                    ? Str::replaceLast('Factory', '', Str::replaceFirst('\\Database\\Factories\\', '\\Models\\', $class))
                    : 'App\\Models\\'.Str::replaceLast('Factory', '', class_basename($factory));

                /** @var class-string<Model> $model */
                return $model;
            }
        );
    }

    /**
     * Map Modules\Foo\Models\Bar to Modules\Foo\Policies\BarPolicy.
     */
    private function resolveModulePolicies(): void
    {
        Gate::guessPolicyNamesUsing(function (string $model): string {
            if (str_starts_with($model, 'Modules\\') && str_contains($model, '\\Models\\')) {
                return Str::replaceFirst('\\Models\\', '\\Policies\\', $model).'Policy';
            }

            return 'App\\Policies\\'.class_basename($model).'Policy';
        });
    }
}
