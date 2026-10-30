<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Schedules\ActivateScheduleAction;
use App\Actions\Schedules\CreateScheduleAction;
use App\Actions\Schedules\DestroyScheduleAction;
use App\Actions\Schedules\ListSchedulesAction;
use App\Actions\Schedules\RunScheduleAction;
use App\Actions\Schedules\ShowScheduleLogsAction;
use App\Data\Operations\OperationData;
use App\Data\Schedules\ScheduleData;
use App\Domain\Schedules\ScheduleOperationAdmission;
use App\Domain\Schedules\ScheduleSwampSafety;
use App\Domain\Schedules\ScheduleTargetResolver;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\StartsOperation;
use App\Http\Requests\Schedules\EmptyScheduleQueryRequest;
use App\Http\Requests\Schedules\EmptyScheduleRequest;
use App\Http\Requests\Schedules\ScheduleLogsRequest;
use App\Http\Requests\Schedules\StoreScheduleRequest;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Schedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SensitiveParameter;

final class SchedulesController extends Controller
{
    public function __construct(private readonly ScheduleSwampSafety $safety, private readonly ScheduleTargetResolver $targets) {}

    #[RequiresNodeAccess(ServingNode::Collection)]
    public function index(EmptyScheduleQueryRequest $request, ListSchedulesAction $action): JsonResponse
    {
        $caller = $request->user();
        abort_unless($caller instanceof Node, 403);

        return response()->json([
            'data' => $action->execute($caller)->all(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::ScheduleOwning)]
    #[StartsOperation('schedule.create')]
    public function store(
        #[SensitiveParameter]
        StoreScheduleRequest $request,
        CreateScheduleAction $action,
    ): JsonResponse {
        $data = $request->payload();
        if ($this->safety->requiresSynchronous($this->targets->resolve($data->targetType, $data->targetId)->node)) {
            $result = $action->execute($data);
            $this->rememberForActivity($request, $result['schedule']);

            return response()->json([
                'data' => ScheduleData::fromModel($result['schedule'])->toArray(),
                'meta' => $this->meta($request),
            ], $result['created'] ? 201 : 200);
        }

        return $this->operationResponse($request, $action->admit($data, $this->caller($request), $request->attributes->getString('orbit.request_id')));
    }

    #[RequiresNodeAccess(ServingNode::ScheduleOwning)]
    public function show(
        EmptyScheduleQueryRequest $request,
        #[SensitiveParameter]
        Schedule $schedule,
    ): JsonResponse {
        return $this->scheduleResponse($request, $schedule);
    }

    #[RequiresNodeAccess(ServingNode::ScheduleOwning)]
    #[StartsOperation('schedule.run')]
    public function run(
        EmptyScheduleRequest $request,
        #[SensitiveParameter]
        Schedule $schedule,
        RunScheduleAction $action,
    ): JsonResponse {
        if ($this->synchronous($schedule, 'run')) {
            return $this->scheduleResponse($request, $action->execute($schedule));
        }

        return $this->operationResponse($request, $action->admit($schedule, $this->caller($request), $request->attributes->getString('orbit.request_id')));
    }

    #[RequiresNodeAccess(ServingNode::ScheduleOwning)]
    public function logs(
        ScheduleLogsRequest $request,
        #[SensitiveParameter]
        Schedule $schedule,
        ShowScheduleLogsAction $action,
    ): JsonResponse {
        $this->rememberForActivity($request, $schedule);
        $lines = $request->lines();
        $logs = $action->execute($schedule, $lines);

        return response()->json([
            'data' => [
                'id' => $schedule->id,
                'name' => $schedule->name,
                'lines' => $lines,
                'output' => $logs->output,
                'truncated' => $logs->truncated,
            ],
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::ScheduleOwning)]
    #[StartsOperation('schedule.destroy')]
    public function destroy(
        EmptyScheduleRequest $request,
        #[SensitiveParameter]
        string $schedule,
        DestroyScheduleAction $action,
    ): JsonResponse {
        $record = Schedule::query()->findOrFail($schedule);
        $request->route()?->setParameter('schedule', $record);
        if ($this->synchronous($record, 'destroy')) {
            return $this->scheduleResponse($request, $action->execute($record));
        }

        return $this->operationResponse($request, $action->admit($record, caller: $this->caller($request), requestId: $request->attributes->getString('orbit.request_id')));
    }

    #[RequiresNodeAccess(ServingNode::ScheduleOwning)]
    #[StartsOperation('schedule.enable')]
    public function activate(
        EmptyScheduleRequest $request,
        #[SensitiveParameter]
        Schedule $schedule,
        ActivateScheduleAction $action,
    ): JsonResponse {
        if ($this->synchronous($schedule, 'enable')) {
            return $this->scheduleResponse($request, $action->execute($schedule));
        }

        return $this->operationResponse($request, $action->admit($schedule, $this->caller($request), $request->attributes->getString('orbit.request_id')));
    }

    private function synchronous(Schedule $schedule, string $verb): bool
    {
        return ! app(ScheduleOperationAdmission::class)->direct($verb)
            && $this->safety->requiresSynchronous($this->targets->forInspection($schedule)->node);
    }

    private function scheduleResponse(Request $request, #[SensitiveParameter] Schedule $schedule): JsonResponse
    {
        $this->rememberForActivity($request, $schedule);

        return response()->json([
            'data' => ScheduleData::fromModel($schedule)->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    private function rememberForActivity(Request $request, #[SensitiveParameter] Schedule $schedule): void
    {
        $request->attributes->set('orbit.schedule_activity', $schedule);
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
