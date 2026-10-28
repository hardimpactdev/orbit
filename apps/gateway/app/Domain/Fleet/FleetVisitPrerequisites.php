<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Schema;

/** Discover installed contracts. No enable switch can stand in for a missing executor primitive. */
final readonly class FleetVisitPrerequisites
{
    public function __construct(private OperationKinds $kinds, private Router $router) {}

    public function assertAvailable(): void
    {
        if (! FleetKitCapabilities::visitsSupported()) {
            $this->refuse();
        }
        $required = [
            'api/v1/operation-executor/{operation}/children' => ['GET', 'POST'],
            'api/v1/operation-executor/{operation}/receipts/{step}' => ['GET'],
            'api/v1/operation-executor/{operation}/wait' => ['POST'],
        ];
        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            foreach ($required as $uri => $methods) {
                // Parameter names are not part of the public route contract.
                if (preg_replace('/\{[^}]+\}/', '{}', $route->uri()) === preg_replace('/\{[^}]+\}/', '{}', $uri)) {
                    $required[$uri] = array_values(array_diff($methods, $route->methods()));
                }
            }
        }
        $kind = $this->kinds->definition('node.converge');
        if (array_any($required, static fn (array $methods): bool => $methods !== [])
            || ! Schema::hasColumns('operations', ['parent_operation_id', 'child_key', 'run_constraints'])
            || ! is_array($kind) || ! class_exists(OperationChildren::class)) {
            $this->refuse();
        }
    }

    private function refuse(): never
    {
        throw new ResourceOperationException('operation.kind_unavailable', 'Fleet visits require the supported child and list contracts, receipt API, and Node converge kind to be installed.', 409);
    }
}
