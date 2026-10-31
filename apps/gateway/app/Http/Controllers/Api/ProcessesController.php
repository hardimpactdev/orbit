<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Processes\CreateProcessAction;
use App\Actions\Processes\DestroyProcessAction;
use App\Actions\Processes\ListProcessesAction;
use App\Actions\Processes\RestartProcessAction;
use App\Actions\Processes\ShowProcessLogsAction;
use App\Actions\Processes\StartProcessAction;
use App\Actions\Processes\StopProcessAction;
use App\Actions\Processes\UpdateProcessAction;
use App\Data\Operations\OperationData;
use App\Data\Processes\ProcessData;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessSwampSafety;
use App\Domain\Processes\ProcessTargetResolver;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\StartsOperation;
use App\Http\Requests\Processes\ListProcessesRequest;
use App\Http\Requests\Processes\ProcessLogsRequest;
use App\Http\Requests\Processes\StoreProcessRequest;
use App\Http\Requests\Processes\UpdateProcessRequest;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SensitiveParameter;

#[RequiresNodeAccess(ServingNode::ProcessOwning)]
final class ProcessesController extends Controller
{
    public function __construct(private readonly ProcessSwampSafety $safety, private readonly ProcessTargetResolver $targets, private readonly ProcessRuntimeManager $runtime) {}

    public function index(ListProcessesRequest $request, ListProcessesAction $action): JsonResponse
    {
        $processes = $request->hasTarget()
            ? $action->execute($request->targetType(), $request->targetId())
            : $action->executeAll();

        return response()->json([
            'data' => $processes->all(),
            'meta' => $this->meta($request),
        ]);
    }

    #[StartsOperation('process.create')]
    public function store(StoreProcessRequest $request, CreateProcessAction $action): JsonResponse
    {
        $data = $request->payload();

        return $this->operationResponse($request, $action->admit($data, $this->caller($request), $request->attributes->getString('orbit.request_id')));
    }

    #[StartsOperation('process.update')]
    public function update(UpdateProcessRequest $request, Process $process, UpdateProcessAction $action): JsonResponse
    {
        return $this->operationResponse($request, $action->admit($process, $request->updates(), $this->caller($request), $request->attributes->getString('orbit.request_id')));
    }

    #[StartsOperation('process.start')]
    public function start(Request $request, Process $process, StartProcessAction $action): JsonResponse
    {
        if ($this->synchronous($process)) {
            return $this->processResponse($request, $action->execute($process));
        }

        return $this->operationResponse($request, $action->admit($process, $this->caller($request), $request->attributes->getString('orbit.request_id')));
    }

    #[StartsOperation('process.stop')]
    public function stop(Request $request, Process $process, StopProcessAction $action): JsonResponse
    {
        return $this->operationResponse($request, $action->admit($process, $this->caller($request), $request->attributes->getString('orbit.request_id')));
    }

    #[StartsOperation('process.restart')]
    public function restart(Request $request, Process $process, RestartProcessAction $action): JsonResponse
    {
        if ($this->synchronous($process)) {
            return $this->processResponse($request, $action->execute($process));
        }

        return $this->operationResponse($request, $action->admit($process, $this->caller($request), $request->attributes->getString('orbit.request_id')));
    }

    public function logs(
        ProcessLogsRequest $request,
        #[SensitiveParameter]
        Process $process,
        ShowProcessLogsAction $action,
    ): JsonResponse {
        $lines = $request->lines();

        return response()->json([
            'data' => [
                'id' => $process->id,
                'name' => $process->name,
                'lines' => $lines,
                'logs' => $action->execute($process, $lines),
            ],
            'meta' => $this->meta($request),
        ]);
    }

    #[StartsOperation('process.destroy')]
    public function destroy(Request $request, string $process, DestroyProcessAction $action): JsonResponse
    {
        $record = Process::query()->findOrFail($process);
        $request->route()?->setParameter('process', $record);

        return $this->operationResponse($request, $action->admit($record, caller: $this->caller($request), requestId: $request->attributes->getString('orbit.request_id')));
    }

    private function synchronous(Process $process): bool
    {
        return $this->safety->requiresSynchronous($this->targets->forInspection($process)->node);
    }

    private function processResponse(Request $request, #[SensitiveParameter] Process $process, int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => ProcessData::fromModel($process, $this->runtime->status($process))->toArray(),
            'meta' => $this->meta($request),
        ], $status);
    }

    private function operationResponse(Request $request, Operation $operation): JsonResponse
    {
        return response()->json(['data' => OperationData::fromModel($operation)->toArray(), 'meta' => $this->meta($request)], 202);
    }

    private function caller(Request $request): ?Node
    {
        $caller = $request->user();

        return $caller instanceof Node ? $caller : null;
    }

    /** @return array{request_id: string} */
    private function meta(Request $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }
}
