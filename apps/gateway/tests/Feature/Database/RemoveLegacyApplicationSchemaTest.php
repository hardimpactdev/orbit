<?php

declare(strict_types=1);

use App\Http\Controllers\Api\InstancesController;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\Schema;

it('leaves a fresh Gateway schema without leftover Instance or Workspace tables or columns', function (): void {
    expect(Schema::hasTable('workspaces'))
        ->toBeFalse()
        ->and(Schema::hasTable('projects'))
        ->toBeTrue()
        ->and(Schema::hasTable('instances'))
        ->toBeTrue()
        ->and(Schema::hasTable('routes'))
        ->toBeTrue()
        ->and(Schema::hasTable('processes'))
        ->toBeTrue();

    foreach (['projects', 'instances', 'nodes', 'routes', 'route_targets', 'processes'] as $table) {
        expect(Schema::hasColumn($table, 'workspace_id'))
            ->toBeFalse()
            ->and(Schema::hasColumn($table, 'certificate_mode'))
            ->toBeFalse()
            ->and(Schema::hasColumn($table, 'checkout_path_origin'))
            ->toBeFalse();
    }
});

it('supplies no conversion command, script, API, SDK operation, or completion gate', function (): void {
    $named = collect(RouteFacade::getRoutes()->getRoutes())
        ->filter(static fn (IlluminateRoute $route): bool => is_string($route->getName()))
        ->mapWithKeys(static fn (IlluminateRoute $route): array => [
            $route->getName() => [$route->methods()[0], $route->uri(), $route->getControllerClass()],
        ])
        ->all();

    expect($named)
        ->not
        ->toHaveKeys([
            'instance:convert',
            'workspace:convert',
            'instance:migrate',
            'workspace:migrate',
        ])
        ->and($named['instance:list'] ?? null)
        ->toBe(['GET', 'api/v1/instances', InstancesController::class]);

    foreach ($named as [$method, $uri]) {
        expect($uri)
            ->not
            ->toContain('convert')
            ->and($uri)
            ->not
            ->toContain('migrate');
        expect($method)->toBeString();
    }

    expect(class_exists('App\\Actions\\Instances\\ConvertInstanceAction'))
        ->toBeFalse()
        ->and(class_exists('App\\Actions\\Workspaces\\ConvertWorkspaceAction'))
        ->toBeFalse()
        ->and(class_exists('App\\Console\\Commands\\ConvertLegacyApplicationsCommand'))
        ->toBeFalse();
});
