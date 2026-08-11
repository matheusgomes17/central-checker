<?php

declare(strict_types=1);

namespace Modules\Core\Support;

use Modules\Core\Providers\ModuleServiceProvider;

/**
 * Read-only view over the modules enabled in config/modules.php.
 */
class Modules
{
    /**
     * The studly names of every enabled module, in registration order.
     *
     * @return array<int, string>
     */
    public function enabled(): array
    {
        /** @var array<int, string> $modules */
        $modules = config('modules.enabled', []);

        return $modules;
    }

    public function isEnabled(string $module): bool
    {
        return in_array($module, $this->enabled(), strict: true);
    }

    /**
     * The service provider class for every enabled module.
     *
     * @return array<int, class-string<ModuleServiceProvider>>
     */
    public function providers(): array
    {
        $providers = [];

        foreach ($this->enabled() as $module) {
            /** @var class-string<ModuleServiceProvider> $provider */
            $provider = "Modules\\{$module}\\Providers\\{$module}ServiceProvider";

            $providers[] = $provider;
        }

        return $providers;
    }

    /**
     * Absolute path to a module directory, or to the modules root.
     */
    public function path(string $module = '', string $relative = ''): string
    {
        $path = base_path('modules');

        if ($module !== '') {
            $path .= '/'.$module;
        }

        return $relative === '' ? $path : $path.'/'.ltrim($relative, '/');
    }
}
