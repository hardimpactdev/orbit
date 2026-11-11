<?php

declare(strict_types=1);

use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Middleware\RequireNodeAccess;
use App\Http\Requests\Tools\ListToolManagersRequest;
use App\Http\Requests\Tools\ListToolsRequest;
use Illuminate\Support\Facades\Route;

it('requires ownership metadata or an explicit fleet-global policy on every GET collection route', function (): void {
    $fleetGlobal = [
        'ai-router:provider:list' => [ServingNode::Gateway, 'Provider configuration requires Gateway authority.'],
        'ai-router:model:list' => [ServingNode::Gateway, 'Model configuration requires Gateway authority.'],
        'database:list' => [ServingNode::Gateway, 'Database connections require Gateway authority.'],
        'database:user:list' => [ServingNode::Gateway, 'Database users require Gateway authority.'],
        'database:server:list' => [ServingNode::Gateway, 'Database servers require Gateway authority.'],
        'extension:list' => [ServingNode::Gateway, 'Fleet extension switches require Gateway authority.'],
        'gateway:release:list' => [ServingNode::Gateway, 'Gateway release history requires Gateway authority.'],
        'task:agent:list' => [ServingNode::Gateway, 'Task agent sessions require Gateway authority.'],
        'task:comment:list' => [ServingNode::Gateway, 'Task comments require Gateway authority (docs/reference/tasks.md#tasks-and-subtasks).'],
        'activity:list' => [ServingNode::Gateway, 'Activities require Gateway authority.'],
        'websocket:app:list' => [ServingNode::Gateway, 'Websocket apps require Gateway authority.'],
        'task:list' => [ServingNode::Collection, 'The fleet board is shared by authorized peers (docs/reference/tasks.md#tasks-and-subtasks).'],
        'task:question:list' => [ServingNode::Collection, 'Questions share the documented fleet board.'],
    ];
    $violations = [];
    $checked = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/') || ! in_array('GET', $route->methods(), true)
            || (! str_ends_with($route->getName() ?? '', ':list') && $route->getActionMethod() !== 'index')) {
            continue;
        }

        $name = $route->getName() ?? $route->uri();
        $checked[] = $name;
        $controllerClass = $route->getControllerClass();
        if ($controllerClass === null || ! class_exists($controllerClass)) {
            $violations[$name] = 'A collection route must have a controller with access metadata.';

            continue;
        }
        $class = new ReflectionClass($controllerClass);
        $method = $class->getMethod($route->getActionMethod());
        $attribute = $method->getAttributes(RequiresNodeAccess::class)[0] ?? $class->getAttributes(RequiresNodeAccess::class)[0] ?? null;
        $scope = $attribute?->newInstance()->servingNode;
        if (! in_array(RequireNodeAccess::class, $route->gatherMiddleware(), true) || $scope === null) {
            $violations[$name] = 'Missing Node access metadata or middleware.';
        } elseif (isset($fleetGlobal[$name])) {
            if ($scope !== $fleetGlobal[$name][0]) {
                $violations[$name] = $fleetGlobal[$name][1];
            }
        } elseif ($scope === ServingNode::Gateway) {
            $violations[$name] = 'Name the fleet-global policy and its reason.';
        } elseif (in_array($scope->name, ['Collection', 'ProcessCollection'], true)) {
            continue;
        } elseif ($scope === ServingNode::ToolOwning) {
            // Both Tool lists require node_id; optional targets must use a collection policy.
            $requestClass = match ($name) {
                'tool:list' => ListToolsRequest::class,
                'tool:manager:list' => ListToolManagersRequest::class,
                default => null,
            };
            $requestType = $method->getParameters()[0]->getType();
            if ($requestClass === null || ! $requestType instanceof ReflectionNamedType || $requestType->getName() !== $requestClass
                || ! in_array('required', (new $requestClass)->rules()['node_id'], true)) {
                $violations[$name] = 'A Tool list must require its owning Node.';
            }
        } else {
            $ownerParameter = match ($scope) {
                ServingNode::Target => 'node',
                ServingNode::InstanceOwning, ServingNode::EnvironmentInstanceOwning => 'instance',
                ServingNode::ProjectOwning => 'project',
                ServingNode::ProcessOwning => 'process',
                default => null,
            };
            if ($ownerParameter === null || ! in_array($ownerParameter, $route->parameterNames(), true)) {
                $violations[$name] = 'An untargeted list must declare a collection policy.';
            }
        }
    }

    expect($checked)->toContain('process:list', ...array_keys($fleetGlobal));
    expect($violations)->toBe([]);
});
