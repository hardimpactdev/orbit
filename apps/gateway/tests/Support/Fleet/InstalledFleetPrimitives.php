<?php

declare(strict_types=1);

namespace Tests\Support\Fleet;

use App\Domain\Fleet\FleetKitCapabilities;
use App\Domain\Operations\OperationKinds;
use App\Models\Operation;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/** Presence fixtures only. They never implement or execute shared primitives. */
final class InstalledFleetPrimitives
{
    public static function requireSupportedKit(): void
    {
        if (! FleetKitCapabilities::visitsSupported()) {
            test()->markTestSkipped('Shared kit children, list_of and receipts are not installed in this base.');
        }
    }

    public static function simulate(?string $missing = null): void
    {
        Operation::query()->count();
        $suffix = match ($missing) {
            'children' => '/children', 'wait' => '/wait', 'receipts' => '/receipts/{}', default => null,
        };
        if ($suffix !== null) {
            $routes = new RouteCollection;
            foreach (Route::getRoutes()->getRoutes() as $route) {
                $uri = preg_replace('/\{[^}]+\}/', '{}', $route->uri());
                if ($uri !== 'api/v1/operation-executor/{}'.$suffix) {
                    $routes->add($route);
                }
            }
            Route::setRoutes($routes);
        }
        if ($missing !== 'children') {
            Route::match(['GET', 'POST'], 'api/v1/operation-executor/{operation}/children', static fn () => abort(501));
        }
        if ($missing !== 'wait') {
            Route::post('api/v1/operation-executor/{operation}/wait', static fn () => abort(501));
        }
        if ($missing !== 'receipts') {
            Route::get('api/v1/operation-executor/{operation}/receipts/{step}', static fn () => abort(501));
        }
        Schema::shouldReceive('hasColumns')->with('operations', ['parent_operation_id', 'child_key', 'run_constraints'])->andReturn($missing !== 'columns');
        $kinds = OperationKinds::definitions();
        if ($missing !== 'kind') {
            $kinds['node.converge'] = $kinds['fleet.recipe'] ?? $kinds['fleet.peer_dns'];
        } else {
            unset($kinds['node.converge']);
        }
        app()->instance(OperationKinds::class, new OperationKinds(app(), $kinds));
    }
}
