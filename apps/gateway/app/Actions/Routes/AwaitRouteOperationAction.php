<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use App\Domain\Operations\AwaitOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;

/** Retained synchronous callers read the normal Operation; they never execute it. */
final readonly class AwaitRouteOperationAction
{
    public function __construct(private AwaitOperation $await) {}

    public function execute(Operation $operation): Operation
    {
        $operation = $this->await->execute($operation);
        if ($operation->status !== OperationStatus::Succeeded) {
            throw new ResourceOperationException($operation->error_code ?? 'operation.cancelled', 'The Route Operation did not succeed.', 409,
                details: ['operation_id' => $operation->id]);
        }

        return $operation;
    }

    public function route(Operation $operation): Route
    {
        $operation = $this->execute($operation);

        return Route::query()->with('targets')->findOrFail(ValidatedData::integer($operation->result['route_id'] ?? null));
    }
}
