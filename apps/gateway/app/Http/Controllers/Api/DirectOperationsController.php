<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\GitHub\IssueDirectGitHubTokenAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationPrivateMaterial;
use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Shared\ResourceOperationException;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\DirectOperationPrivateRequest;
use App\Http\Requests\Operations\DirectOperationRequest;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Http\JsonResponse;

#[RequiresNodeAccess(ServingNode::Gateway)]
final class DirectOperationsController extends Controller
{
    public function show(DirectOperationRequest $request, Operation $operation, DirectOperation $direct): JsonResponse
    {
        $direct->projection($operation);

        return $this->respond($request, $this->operation($operation));
    }

    public function primitive(DirectOperationRequest $request, Operation $operation, DirectOperation $direct, OperationExecutionContract $contract): JsonResponse
    {
        $direct->projection($operation);
        if ($operation->status->terminal()) {
            throw new ResourceOperationException('operation.finished', 'The Operation has ended.', 409);
        }

        return $this->respond($request, $contract->result($operation, $direct->primitive($operation)->execute($operation)));
    }

    public function githubToken(DirectOperationRequest $request, Operation $operation, IssueDirectGitHubTokenAction $action): JsonResponse
    {
        $issued = $action->handle($operation);
        $grant = $issued['grant'];

        return $this->respond($request, [
            'repository' => $grant->repository->owner.'/'.$grant->repository->name,
            'permissions' => $grant->permissions,
            'token' => $issued['credential']->reveal(),
            'expires_at' => $issued['credential']->expiresAt,
        ])->header('Cache-Control', 'no-store');
    }

    public function result(DirectOperationRequest $request, Operation $operation, DirectOperation $direct): JsonResponse
    {
        return $this->respond($request, $this->operation($direct->projection($operation)->apply($operation, $request->validated())));
    }

    public function privateMaterial(DirectOperationPrivateRequest $request, Operation $operation, DirectOperationPrivateMaterial $material): JsonResponse
    {
        return $this->respond($request, $material->read($operation))->header('Cache-Control', 'no-store');
    }

    public function secretOutput(DirectOperationPrivateRequest $request, Operation $operation, DirectOperationPrivateMaterial $material): JsonResponse
    {
        return $this->respond($request, ['stored' => $material->write($operation, ValidatedData::object($request->validated('outputs')))])->header('Cache-Control', 'no-store');
    }

    /** @return array<string, mixed> */
    private function operation(Operation $operation): array
    {
        return ['id' => $operation->id, 'kind' => $operation->kind, 'node_id' => $operation->node_id,
            'input' => app(DirectOperation::class)->projection($operation)->input($operation),
            'status' => $operation->status->value, 'result' => $operation->result, 'cancel_requested' => $operation->cancel_requested_at !== null];
    }

    /** @param array<string, mixed> $data */
    private function respond(DirectOperationRequest $request, array $data): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }
}
