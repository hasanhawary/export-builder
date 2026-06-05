<?php

namespace HasanHawary\ExportBuilder\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;

class ExportRoutes
{
    public function register(): void
    {
        if (! $this->routesAreEnabled()) {
            return;
        }

        $prefix = $this->routePrefix();
        $namePrefix = $this->routeNamePrefix();
        $middleware = Arr::wrap(config('export.module.routes.middleware', ['api']));
        $directController = config('export.module.controllers.direct');
        $jobController = config('export.module.controllers.jobs');
        $exportUri = $this->routeUri($prefix, (string) config('export.module.routes.export_path', 'export'));
        $directUri = $this->routeUri($prefix, (string) config('export.module.routes.direct_path', 'export-direct'));
        $logUri = $this->routeUri($prefix, (string) config('export.module.routes.log_path', 'export-log'));

        $this->addRoute('get', $directUri, 'direct', $directController, $namePrefix, $middleware);
        $this->addRoute('get', $exportUri, 'download', $directController, $namePrefix, $middleware);

        if ($jobController) {
            $this->addRoute('post', $exportUri, 'store', [$jobController, 'export'], $namePrefix, $middleware);
            $this->addRoute('get', $logUri, 'logs.index', [$jobController, 'index'], $namePrefix, $middleware);
            $this->addRoute('get', "{$logUri}/{exportFile}", 'logs.show', [$jobController, 'show'], $namePrefix, $middleware);
            $this->addRoute('get', "{$logUri}/{exportFile}/download", 'logs.download', [$jobController, 'download'], $namePrefix, $middleware);
            $this->addRoute('delete', "{$logUri}/{exportFile}", 'logs.destroy', [$jobController, 'destroy'], $namePrefix, $middleware);
        }
    }

    private function routesAreEnabled(): bool
    {
        return (bool) config('export.module.enabled', true)
            && (bool) config('export.module.routes.enabled', true);
    }

    private function routePrefix(): string
    {
        return trim((string) config('export.module.routes.prefix', 'api'), '/');
    }

    private function routeUri(string $prefix, string $path): string
    {
        return trim(trim($prefix, '/').'/'.trim($path, '/'), '/');
    }

    private function routeNamePrefix(): string
    {
        $namePrefix = trim((string) config('export.module.routes.name_prefix', 'export-builder.export.'), '.');

        return $namePrefix === '' ? '' : "{$namePrefix}.";
    }

    /**
     * Register a route only if the host app has not already claimed its name or URI.
     */
    private function addRoute(
        string $method,
        string $uri,
        string $name,
        mixed $action,
        string $namePrefix,
        array $middleware
    ): void {
        $fullName = $namePrefix.$name;

        if (! $action || Route::has($fullName) || $this->routeUriExists($method, $uri)) {
            return;
        }

        Route::{$method}($uri, $action)
            ->middleware($middleware)
            ->name($fullName);
    }

    private function routeUriExists(string $method, string $uri): bool
    {
        $method = strtoupper($method);
        $uri = trim($uri, '/');

        foreach (Route::getRoutes() as $route) {
            if (trim($route->uri(), '/') === $uri && in_array($method, $route->methods(), true)) {
                return true;
            }
        }

        return false;
    }
}
