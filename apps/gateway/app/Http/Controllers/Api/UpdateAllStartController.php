<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Exceptions\UpdateLeaseConflict;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Http\Requests\Api\UpdateAllStartApiRequest;
use App\Models\Node;
use App\Models\OperationRun;
use App\Models\OperationUpdatePlan;
use App\Models\UpdateLease;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationUpdatePlanStore;
use App\Services\Operations\UpdatePlanBuilder;
use App\Services\Operations\UpdateRunnerDispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Orbit\Core\Enums\OperationStatus;
use RuntimeException;

#[RequiresPermission('*', servingNode: ServingNode::Gateway)]
class UpdateAllStartController implements Loggable
{
    private ?Node $activitySubject = null;

    private ?OperationRun $activityOperationRun = null;

    private ?OperationUpdatePlan $activityUpdatePlan = null;

    public function __invoke(
        UpdateAllStartApiRequest $request,
        OperationRunRecorder $operationRuns,
        UpdatePlanBuilder $updatePlanBuilder,
        OperationUpdatePlanStore $updatePlans,
        UpdateRunnerDispatcher $runnerDispatcher,
    ): JsonResponse {
        $this->captureActivitySubject($request);

        $startRequest = $this->startRequestPayload($request);
        $hasInlineManifest = is_array($startRequest['manifest'] ?? null) && $startRequest['manifest'] !== [];

        $operationRun = $operationRuns->queued(
            operationId: (string) Str::uuid(),
            lane: 'gateway',
            operationType: 'update:all',
            callerNodeId: $this->activitySubject?->id,
            result: $hasInlineManifest ? null : ['update_start_request' => $startRequest],
        );
        $this->activityOperationRun = $operationRun;

        $reservation = $this->reserveFleet($operationRun, $operationRuns, $runnerDispatcher);

        if ($reservation instanceof JsonResponse) {
            return $reservation;
        }

        $plan = null;

        if ($hasInlineManifest) {
            try {
                $snapshot = $updatePlanBuilder->fromRequest($operationRun, $request);
                $plan = $updatePlans->create($operationRun, $snapshot);
            } catch (InvalidArgumentException|RuntimeException $exception) {
                $runnerDispatcher->releaseReservation($operationRun, $reservation);
                $this->activityOperationRun = $operationRuns->rejected($operationRun->id, [
                    'code' => 'update_plan_invalid',
                    'message' => $exception->getMessage(),
                ]);

                return response()->json([
                    'error' => [
                        'code' => 'validation_failed',
                        'message' => $exception->getMessage(),
                        'meta' => [
                            'reason' => 'update_plan_invalid',
                        ],
                    ],
                ], 422);
            }

            $this->activityUpdatePlan = $plan;
        }

        $operationRuns->appendTree($operationRun->id, 'Update all', [
            ['key' => 'plan', 'label' => 'Resolve update plan'],
            ['key' => 'runner', 'label' => 'Start update runner'],
            ['key' => 'gateway', 'label' => 'Update gateway'],
            ['key' => 'workloads', 'label' => 'Update workload nodes'],
            ['key' => 'verification', 'label' => 'Verify fleet'],
        ]);
        $operationRuns->appendStep($operationRun->id, 'plan', 'done', 'Update plan persisted');

        $runnerDispatcher->dispatchAfterResponse(
            $operationRun,
            $reservation,
            function (RuntimeException $exception) use ($operationRun, $operationRuns): void {
                $operationRuns->appendStep($operationRun->id, 'runner', 'failed', 'Update runner launch failed');
                $operationRuns->appendError($operationRun->id, 'Update runner launch failed', 1, [
                    'reason' => 'update_runner_launch_failed',
                ]);
                $this->activityOperationRun = $operationRuns->failed($operationRun->id, error: [
                    'code' => 'update_runner_launch_failed',
                    'message' => $exception->getMessage(),
                ]);
            },
        );

        return response()->json([
            'success' => [
                'data' => array_filter(
                    [
                        'operation_run' => $this->operationRunPayload($operationRun),
                        'update_plan' => $plan instanceof OperationUpdatePlan
                            ? $this->updatePlanPayload($plan)
                            : null,
                        'events_url' => route('api.operations.events', ['operationRun' => $operationRun->id], false),
                    ],
                    fn (mixed $value): bool => $value !== null,
                ),
            ],
        ], 202);
    }

    private function reserveFleet(
        OperationRun $operationRun,
        OperationRunRecorder $operationRuns,
        UpdateRunnerDispatcher $runnerDispatcher,
    ): UpdateLease|JsonResponse {
        try {
            return $runnerDispatcher->reserve($operationRun);
        } catch (UpdateLeaseConflict $exception) {
            $this->activityOperationRun = $operationRuns->rejected($operationRun->id, [
                'code' => 'update_lease_conflict',
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'error' => [
                    'code' => 'update_lease_conflict',
                    'message' => $exception->getMessage(),
                    'meta' => [
                        'resource' => "{$exception->resourceType}:{$exception->resourceKey}",
                        'resource_type' => $exception->resourceType,
                        'resource_key' => $exception->resourceKey,
                        'lease_id' => $exception->leaseId,
                        'conflicting_operation_id' => $exception->operationRunId,
                        'expires_at' => $exception->expiresAt->toIso8601String(),
                    ],
                ],
            ], 409);
        }
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    public function type(): string
    {
        return 'api:POST /update/all/start';
    }

    public function subject(): ?Model
    {
        return $this->activitySubject;
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return array_filter(
            [
                'operation_run_id' => $this->activityOperationRun?->id,
                'operation_id' => $this->activityOperationRun?->operation_id,
                'operation_status' => $this->activityOperationRun?->status->value,
                'target_version' => $this->activityUpdatePlan?->target_version,
                'gateway_image' => $this->activityUpdatePlan?->gateway_image,
            ],
            fn (mixed $value): bool => $value !== null,
        );
    }

    public function description(): ?string
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function startRequestPayload(UpdateAllStartApiRequest $request): array
    {
        return array_filter(
            [
                'target_version' => $request->input('target_version'),
                'manifest_source' => $request->input('manifest_source'),
                'manifest_version' => $request->input('manifest_version'),
                'manifest' => $request->input('manifest'),
                'gateway_image' => $request->input('gateway_image'),
                'cli_artifacts' => $request->input('cli_artifacts'),
                'agent_artifacts' => $request->input('agent_artifacts'),
                'desktop_artifacts' => $request->input('desktop_artifacts'),
                'role_images' => $request->input('role_images'),
            ],
            fn (mixed $value): bool => $value !== null,
        );
    }

    private function captureActivitySubject(UpdateAllStartApiRequest $request): void
    {
        /** @var mixed $caller */
        $caller = $request->user();

        $this->activitySubject = $caller instanceof Node ? $caller : null;
    }

    /**
     * @return array{id: string, operation_id: string, type: string|null, status: string}
     */
    private function operationRunPayload(OperationRun $operationRun): array
    {
        return [
            'id' => $operationRun->id,
            'operation_id' => $operationRun->operation_id,
            'type' => $operationRun->operation_type,
            'status' => $operationRun->status instanceof OperationStatus
                ? $operationRun->status->value
                : (string) $operationRun->status,
        ];
    }

    /**
     * @return array{target_version: string, gateway_image: string, manifest_source: string, manifest_version: string}
     */
    private function updatePlanPayload(OperationUpdatePlan $plan): array
    {
        return [
            'target_version' => $plan->target_version,
            'gateway_image' => $plan->gateway_image,
            'manifest_source' => $plan->manifest_source,
            'manifest_version' => $plan->manifest_version,
        ];
    }
}
