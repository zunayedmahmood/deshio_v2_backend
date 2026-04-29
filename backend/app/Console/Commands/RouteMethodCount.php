<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

class RouteMethodCount extends Command
{
    /**
     * The name and signature of the console command.
     *
     * - --prefix=   : optional; filter routes by prefix (e.g. "api")
     * - --middleware= : optional; filter routes by middleware name (e.g. "auth:api")
     */
    protected $signature = 'route:method-count
                            {--prefix= : Only include routes whose prefix contains this string}
                            {--middleware= : Only include routes that have this middleware}';

    protected $description = 'Count registered routes grouped by HTTP method (GET, POST, etc.)';

    public function handle()
    {
        $prefixFilter = $this->option('prefix');
        $middlewareFilter = $this->option('middleware');

        $routes = collect(Route::getRoutes())->map(function ($route) {
            return [
                'methods'    => $route->methods(),       // array of methods
                'uri'        => $route->uri(),
                'prefix'     => $route->getPrefix(),
                'middleware' => $route->gatherMiddleware(),
                'name'       => $route->getName(),
            ];
        });

        // apply prefix filter if provided
        if ($prefixFilter) {
            $routes = $routes->filter(function ($r) use ($prefixFilter) {
                $prefix = $r['prefix'] ?: '';
                return str_contains($prefix, $prefixFilter) || str_contains($r['uri'], $prefixFilter);
            });
        }

        // apply middleware filter if provided
        if ($middlewareFilter) {
            $routes = $routes->filter(function ($r) use ($middlewareFilter) {
                foreach ($r['middleware'] as $m) {
                    if (str_contains($m, $middlewareFilter)) {
                        return true;
                    }
                }
                return false;
            });
        }

        // flatten counts by method
        $counts = [];
        $routes->each(function ($r) use (&$counts) {
            foreach ($r['methods'] as $m) {
                // skip internal HEAD added by Laravel when only GET exists (we treat HEAD separately if desired)
                if ($m === 'HEAD') {
                    $counts['HEAD'] = ($counts['HEAD'] ?? 0) + 1;
                    continue;
                }
                // methods can include "GET|HEAD" but Route::methods() returns array, so we're safe
                $counts[$m] = ($counts[$m] ?? 0) + 1;
            }
        });

        // Sort methods by common order: GET, POST, PUT, PATCH, DELETE, OPTIONS, HEAD, others
        $order = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'];
        $ordered = [];
        foreach ($order as $method) {
            if (isset($counts[$method])) {
                $ordered[$method] = $counts[$method];
                unset($counts[$method]);
            }
        }
        // append any remaining methods
        foreach ($counts as $m => $c) {
            $ordered[$m] = $c;
        }

        if (empty($ordered)) {
            $this->info('No routes matched the filters.');
            return 0;
        }

        // Print table
        $rows = [];
        $total = 0;
        foreach ($ordered as $method => $count) {
            $rows[] = [$method, $count];
            $total += $count;
        }
        $rows[] = ['Total', $total];

        $this->table(['Method', 'Count'], $rows);

        return 0;
    }
}
