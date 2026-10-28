<?php

declare(strict_types=1);

namespace Tests\Support\Fleet;

use App\Domain\Fleet\FleetKitCapabilities;
use App\Domain\Operations\OperationKinds;
use App\Models\Operation;
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
        }
        app()->instance(OperationKinds::class, new OperationKinds(app(), $kinds));
    }
}
