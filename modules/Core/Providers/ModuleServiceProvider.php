<?php

declare(strict_types=1);

namespace Modules\Core\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Override;
use ReflectionClass;

/**
 * Base provider for every module under modules/.
 *
 * Subclasses only declare their name; migrations, views, Livewire components,
 * translations and routes are wired automatically from the module's directory
 * layout. Anything module-specific goes in registerModule()/bootModule().
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    private ?string $basePath = null;

    /**
     * The module's studly name, matching its directory (e.g. "Tenancy").
     */
    abstract protected function name(): string;

    #[Override]
    public function register(): void
    {
        foreach ($this->configFiles() as $file) {
            $this->mergeConfigFrom($file, $this->slug().'.'.basename($file, '.php'));
        }

        $this->registerModule();
    }

    public function boot(): void
    {
        $this->bootMigrations();
        $this->bootTranslations();
        $this->bootViews();
        $this->bootRoutes();

        $this->bootModule();
    }

    /**
     * Hook for container bindings owned by the module.
     */
    protected function registerModule(): void
    {
        //
    }

    /**
     * Hook for boot-time wiring owned by the module.
     */
    protected function bootModule(): void
    {
        //
    }

    /**
     * The namespace used for views, Livewire components and translations.
     */
    protected function slug(): string
    {
        return Str::kebab($this->name());
    }

    /**
     * Absolute path inside the module directory.
     */
    protected function path(string $relative = ''): string
    {
        if ($this->basePath === null) {
            /** @var string $file A provider is always defined in a real file. */
            $file = new ReflectionClass($this)->getFileName();

            $this->basePath = dirname($file, 2);
        }

        return $relative === '' ? $this->basePath : $this->basePath.'/'.ltrim($relative, '/');
    }

    private function bootMigrations(): void
    {
        if (is_dir($path = $this->path('database/migrations'))) {
            $this->loadMigrationsFrom($path);
        }
    }

    private function bootTranslations(): void
    {
        if (is_dir($path = $this->path('lang'))) {
            $this->loadTranslationsFrom($path, $this->slug());
            $this->loadJsonTranslationsFrom($path);
        }
    }

    /**
     * Register the module's view directory as a namespace shared by Blade
     * views, anonymous Blade components and Livewire single-file components,
     * mirroring how Livewire wires the application's own "pages" namespace.
     */
    private function bootViews(): void
    {
        if (! is_dir($path = $this->path('resources/views'))) {
            return;
        }

        $namespace = $this->slug();

        View::addNamespace($namespace, $path);
        Blade::anonymousComponentPath($path, $namespace);
        Livewire::addNamespace($namespace, viewPath: $path);
    }

    private function bootRoutes(): void
    {
        if (is_file($web = $this->path('routes/web.php'))) {
            Route::middleware('web')->group($web);
        }

        if (is_file($api = $this->path('routes/api.php'))) {
            Route::middleware('api')
                ->prefix('api')
                ->name('api.')
                ->group($api);
        }

        if (is_file($console = $this->path('routes/console.php'))) {
            require $console;
        }
    }

    /**
     * @return array<int, string>
     */
    private function configFiles(): array
    {
        return glob($this->path('config').'/*.php') ?: [];
    }
}
