<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Data\Doctor\DoctorInstanceTarget;
use App\Data\Doctor\DoctorTargetScope;
use App\Enums\ActivityLogType;
use App\Exceptions\AppSelectionResolutionFailed;
use App\Exceptions\WorkspaceUnsupportedForProduction;
use App\Models\Node;
use App\Services\Doctor\DoctorInstanceTargetResolver;
use App\Services\Doctor\DoctorProgressReportFactory;
use App\Services\Doctor\DoctorPublicVocabulary;
use App\Services\Doctor\DoctorReportRunner;
use App\Services\Doctor\DoctorScopeValidator;
use App\Services\Doctor\DoctorValidationFailure;
use App\Services\Nodes\Access\AuthorizationResult;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Workspaces\WorkspaceRoleGuard;
use App\Support\Streaming\ProgressEventStreamEmitter;
use App\Support\Streaming\ProgressEventStreamResponseFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** @mago-expect lint:kan-defect */
final readonly class DoctorRunController implements Loggable
{
    public function __construct(
        private NodeAccessAuthorizer $authorizer,
        private DoctorInstanceTargetResolver $appTargets,
        private WorkspaceRoleGuard $workspaceRoleGuard,
    ) {}

    public function __invoke(
        Request $request,
        DoctorReportRunner $runner,
        DoctorScopeValidator $validator,
        DoctorProgressReportFactory $progressReports,
        DoctorPublicVocabulary $vocabulary,
        ProgressEventStreamResponseFactory $streams,
    ): JsonResponse|StreamedResponse {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return response()->json([
                'error' => [
                    'code' => 'authorization_failed',
                    'message' => 'Peer identity unknown.',
                    'meta' => [],
                ],
            ], 403);
        }

        $families = $vocabulary->internalFamilies($this->families($request));
        $key = $vocabulary->internalKey($this->key($request));

        $scopeFailure = $this->validateScope($request);

        if ($scopeFailure instanceof JsonResponse) {
            return $scopeFailure;
        }

        if ($this->usesFleetScope($request)) {
            $fleetTargets = $runner->fleetTargetsForFamilies($families);
            $unauthorizedTarget = $fleetTargets
                ->first(
                    fn (Node $target): bool => ! $this->authorizer->allows($caller, $target, 'doctor:verify'),
                );

            if ($unauthorizedTarget instanceof Node) {
                return $this->authorizationFailed(
                    $unauthorizedTarget,
                    'doctor:verify',
                    $this->authorizer->authorize($caller, $unauthorizedTarget, 'doctor:verify'),
                );
            }

            $failure = $validator->validate($families);

            if ($failure instanceof DoctorValidationFailure) {
                return response()->json([
                    'error' => [
                        'code' => $failure->code,
                        'message' => $failure->message,
                        'meta' => $failure->meta,
                    ],
                ], 422);
            }

            try {
                foreach ($fleetTargets as $target) {
                    $this->workspaceRoleGuard->ensureDoctorRequestDoesNotCrossProductionBoundary(
                        caller: $caller,
                        target: $target,
                        families: $families === [] ? $runner->categoriesForNode($target) : $families,
                        hasWorkspaceScope: false,
                    );
                }
            } catch (WorkspaceUnsupportedForProduction $exception) {
                return $this->workspaceUnsupportedForProduction($exception);
            }

            if ($this->wantsEventStream($request)) {
                return $this->streamFleet(
                    $streams,
                    $runner,
                    $vocabulary,
                    $families,
                    $key,
                    $this->wantsCompactProgress($request),
                );
            }

            $doctor = $runner->probeFleet(families: $families, key: $key);

            return response()->json([
                'success' => [
                    'data' => [
                        'doctor' => $vocabulary->publicReport($doctor),
                    ],
                ],
            ]);
        }

        try {
            $appTarget = $this->appTargets->resolve(
                $this->scopeValue($request, 'instance'),
                $caller,
                'doctor:verify',
            );
        } catch (AppSelectionResolutionFailed $exception) {
            return $this->appTargetFailure($exception);
        }

        $target = $this->resolveTarget($request, $caller, $appTarget);

        if ($target === null) {
            return response()->json([
                'error' => [
                    'code' => 'scope_not_found',
                    'message' => 'Target node could not be resolved.',
                    'meta' => ['node' => $request->input('node')],
                ],
            ], 422);
        }

        if ($appTarget instanceof DoctorInstanceTarget && $target->id !== $appTarget->node->id) {
            return response()->json([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => "Instance '{$appTarget->app->name}.{$appTarget->instance->name}' is not placed on node '{$target->name}'.",
                    'meta' => [
                        'field' => 'node',
                        'reason' => 'instance_node_mismatch',
                        'node' => $target->name,
                        'serving_node' => $appTarget->node->name,
                    ],
                ],
            ], 422);
        }

        $scope = $appTarget instanceof DoctorInstanceTarget
            ? $appTarget->scope($this->scopeValue($request, 'workspace'))
            : $this->doctorTargetScope($request);

        $authorization = $this->authorizer->authorize($caller, $target, 'doctor:verify');

        if (! $authorization->allowed) {
            return $this->authorizationFailed($target, 'doctor:verify', $authorization);
        }

        $failure = $validator->validate($families, $target, $scope);

        if ($failure instanceof DoctorValidationFailure) {
            return response()->json([
                'error' => [
                    'code' => $failure->code,
                    'message' => $failure->message,
                    'meta' => $failure->meta,
                ],
            ], 422);
        }

        try {
            $this->workspaceRoleGuard->ensureDoctorRequestDoesNotCrossProductionBoundary(
                caller: $caller,
                target: $target,
                families: $families === [] ? $runner->categoriesForNode($target) : $families,
                hasWorkspaceScope: $scope->workspace !== null,
            );
        } catch (WorkspaceUnsupportedForProduction $exception) {
            return $this->workspaceUnsupportedForProduction($exception);
        }

        if ($this->wantsEventStream($request)) {
            return $this->stream(
                $streams,
                $runner,
                $progressReports,
                $vocabulary,
                $target,
                $families,
                $key,
                $scope,
                $this->wantsCompactProgress($request),
            );
        }

        $doctor = $runner->probe(
            $target,
            families: $families,
            key: $key,
            scope: $scope,
        );

        return response()->json([
            'success' => [
                'data' => [
                    'doctor' => $vocabulary->publicReport($doctor),
                ],
            ],
        ]);
    }

    /**
     * @param  list<string>  $families
     * @mago-expect lint:halstead
     * @mago-expect lint:excessive-parameter-list
     * @mago-expect lint:no-boolean-flag-parameter
     */
    private function stream(
        ProgressEventStreamResponseFactory $streams,
        DoctorReportRunner $runner,
        DoctorProgressReportFactory $progressReports,
        DoctorPublicVocabulary $vocabulary,
        Node $target,
        array $families,
        ?string $key,
        DoctorTargetScope $scope,
        bool $compactProgress = false,
    ): StreamedResponse {
        return $streams->make(function (ProgressEventStreamEmitter $events) use (
            $runner,
            $progressReports,
            $vocabulary,
            $target,
            $families,
            $key,
            $scope,
            $compactProgress,
        ): void {
            $renderedFamilies = $families === [] ? $runner->categoriesForNode($target) : $families;
            $familyStatuses = $progressReports->familyStatuses($renderedFamilies);
            /** @var array<string, array{completed: int, total: int}> $familyCheckCounts */
            $familyCheckCounts = [];
            /** @var list<array<string, mixed>> $issues */
            $issues = [];

            $events->stepEvent(
                '__doctor_panel',
                'running',
                'Doctor queued',
                $compactProgress
                    ? ['compact_progress' => true]
                    : [
                        'doctor' => $vocabulary->publicReport($progressReports->report(
                            target: $target,
                            mode: 'verify',
                            families: $renderedFamilies,
                            key: $key,
                            issues: $issues,
                            actions: [],
                            familyStatuses: $familyStatuses,
                            familyCheckCounts: $familyCheckCounts,
                            app: $scope->app,
                            workspace: $scope->workspace,
                            instance: $scope->instance,
                        )),
                    ],
            );
            $events->tree('Running Doctor', array_map(
                fn (string $family): array => [
                    'key' => $vocabulary->publicFamily($family),
                    'label' => "Check {$vocabulary->publicFamily($family)}",
                ],
                $renderedFamilies,
            ));

            /** @param  list<array<string, mixed>>  $familyIssues */
            $onFamilyProgress = function (
                string $family,
                string $phase,
                array $familyIssues = [],
                ?int $completed = null,
                ?int $total = null,
            ) use (
                $events,
                $progressReports,
                $vocabulary,
                $target,
                $key,
                $renderedFamilies,
                $scope,
                $compactProgress,
                &$familyStatuses,
                &$familyCheckCounts,
                &$issues,
            ): void {
                if ($phase === 'running') {
                    $familyStatuses[$family] = 'checking';

                    if ($completed !== null && $total !== null && $total > 0) {
                        $familyCheckCounts[$family] = [
                            'completed' => $completed,
                            'total' => $total,
                        ];
                    }
                }

                if ($phase === 'done') {
                    $issues = [
                        ...$issues,
                        ...$familyIssues,
                    ];
                    $familyStatuses[$family] = 'done';
                    unset($familyCheckCounts[$family]);
                }

                $events->stepEvent(
                    $vocabulary->publicFamily($family),
                    $phase,
                    $phase === 'running'
                        ? "Checking {$vocabulary->publicFamily($family)}"
                        : "{$vocabulary->publicFamily($family)} checked",
                    $compactProgress
                        ? [
                            'compact_progress' => true,
                            'family' => $vocabulary->publicFamily($family),
                            'phase' => $phase,
                        ]
                        : [
                            'doctor' => $vocabulary->publicReport($progressReports->report(
                                target: $target,
                                mode: 'verify',
                                families: $renderedFamilies,
                                key: $key,
                                issues: $issues,
                                actions: [],
                                familyStatuses: $familyStatuses,
                                familyCheckCounts: $familyCheckCounts,
                                app: $scope->app,
                                workspace: $scope->workspace,
                                instance: $scope->instance,
                            )),
                        ],
                );
            };

            $doctor = $runner->probe(
                $target,
                families: $families,
                key: $key,
                onFamilyProgress: $onFamilyProgress,
                scope: $scope,
            );
            $doctor = $vocabulary->publicReport($doctor);

            if (($doctor['healthy'] ?? false) === true) {
                $events->complete(0, [
                    'footer' => 'Doctor completed.',
                    'doctor' => $doctor,
                ]);

                return;
            }

            $events->error('Doctor detected drift.', 1, [
                'code' => 'drift_detected',
                'message' => 'Doctor detected drift.',
                'meta' => [],
                'data' => ['doctor' => $doctor],
                'footer' => 'Doctor detected drift.',
            ]);
        });
    }

    /**
     * @param  list<string>  $families
     * @mago-expect lint:halstead
     * @mago-expect lint:excessive-parameter-list
     * @mago-expect lint:no-boolean-flag-parameter
     */
    private function streamFleet(
        ProgressEventStreamResponseFactory $streams,
        DoctorReportRunner $runner,
        DoctorPublicVocabulary $vocabulary,
        array $families,
        ?string $key,
        bool $compactProgress = false,
    ): StreamedResponse {
        return $streams->make(function (ProgressEventStreamEmitter $events) use (
            $runner,
            $vocabulary,
            $families,
            $key,
            $compactProgress,
        ): void {
            $targets = $runner->fleetTargetsForFamilies($families);
            $events->tree(
                'Running Doctor',
                $targets
                    ->map(fn (Node $node): array => [
                        'key' => $node->name,
                        'label' => "Check {$node->name}",
                    ])
                    ->values()
                    ->all(),
            );

            $doctor = $runner->probeFleet(
                families: $families,
                key: $key,
                onNodeProgress: function (Node $node, string $phase, ?array $partialFleetReport = null) use (
                    $events,
                    $vocabulary,
                    $compactProgress,
                ): void {
                    /** @var array<string, mixed>|null $partialFleetReport */
                    if ($compactProgress) {
                        $extra = [
                            'compact_progress' => true,
                            'node' => $node->name,
                            'phase' => $phase,
                        ];
                    } else {
                        $extra = $partialFleetReport !== null
                            ? ['doctor' => $vocabulary->publicReport($partialFleetReport)]
                            : [];
                    }

                    $events->stepEvent(
                        $node->name,
                        $phase,
                        $phase === 'running' ? "Checking {$node->name}" : "{$node->name} checked",
                        $extra,
                    );
                },
            );
            $doctor = $vocabulary->publicReport($doctor);

            if (($doctor['healthy'] ?? false) === true) {
                $events->complete(0, [
                    'footer' => 'Doctor completed.',
                    'doctor' => $doctor,
                ]);

                return;
            }

            $events->error('Doctor detected drift.', 1, [
                'code' => 'drift_detected',
                'message' => 'Doctor detected drift.',
                'meta' => [],
                'data' => ['doctor' => $doctor],
                'footer' => 'Doctor detected drift.',
            ]);
        });
    }

    private function wantsCompactProgress(Request $request): bool
    {
        return $request->boolean('compact_progress');
    }

    private function wantsEventStream(Request $request): bool
    {
        return in_array('text/event-stream', $request->getAcceptableContentTypes(), true);
    }

    private function usesFleetScope(Request $request): bool
    {
        return $request->boolean('all');
    }

    private function scopeValue(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function doctorTargetScope(Request $request): DoctorTargetScope
    {
        return DoctorTargetScope::from(
            $this->scopeValue($request, 'instance'),
            $this->scopeValue($request, 'workspace'),
        );
    }

    /**
     * @return list<string>
     */
    private function families(Request $request): array
    {
        $families = $request->input('families', []);

        if (! is_array($families)) {
            return [];
        }

        return array_values(array_filter(
            $families,
            static fn (mixed $family): bool => is_string($family) && $family !== '',
        ));
    }

    private function resolveTarget(
        Request $request,
        Node $caller,
        ?DoctorInstanceTarget $appTarget = null,
    ): ?Node {
        $name = $request->input('node');

        if (is_string($name) && $name !== '') {
            $target = Node::query()->where('name', $name)->first();

            return $target instanceof Node ? $target : null;
        }

        return $appTarget?->node ?? $caller;
    }

    private function appTargetFailure(AppSelectionResolutionFailed $exception): JsonResponse
    {
        return response()->json(
            [
                'error' => [
                    'code' => $exception->errorCode,
                    'message' => $exception->getMessage(),
                    'meta' => $exception->meta,
                ],
            ],
            $exception->errorCode === 'instance.not_found' ? 404 : 422,
        );
    }

    private function authorizationFailed(
        Node $target,
        string $permission,
        AuthorizationResult $result,
    ): JsonResponse {
        return response()->json([
            'error' => [
                'code' => 'authorization_failed',
                'message' => "This node is not authorized for '{$permission}' on '{$target->name}'.",
                'meta' => [
                    'reason' => $result->reason,
                    'missing_permission' => $result->missingPermission,
                    'serving_node' => $target->name,
                    'mode' => 'verify',
                ],
            ],
        ], 403);
    }

    private function workspaceUnsupportedForProduction(
        WorkspaceUnsupportedForProduction $exception,
    ): JsonResponse {
        return response()->json([
            'error' => [
                'code' => $exception->errorCode(),
                'message' => $exception->getMessage(),
                'meta' => $exception->meta,
            ],
        ], 422);
    }

    private function validateScope(Request $request): ?JsonResponse
    {
        if (strtolower((string) $this->scopeValue($request, 'node')) === 'all') {
            return response()->json([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'Use all=true to run doctor across the fleet; node=all is not supported.',
                    'meta' => ['field' => 'node', 'value' => 'all'],
                ],
            ], 422);
        }

        if (! $request->boolean('all')) {
            return null;
        }

        $conflicts = array_values(array_filter([
            $this->scopeValue($request, 'node') !== null ? 'node' : null,
            $request->boolean('self') ? 'self' : null,
            $this->scopeValue($request, 'instance') !== null ? 'instance' : null,
            $this->scopeValue($request, 'workspace') !== null ? 'workspace' : null,
        ]));

        if ($conflicts === []) {
            return null;
        }

        return response()->json([
            'error' => [
                'code' => 'validation_failed',
                'message' => 'all cannot be combined with node, self, instance, or workspace scope.',
                'meta' => ['fields' => ['all', ...$conflicts]],
            ],
        ], 422);
    }

    private function key(Request $request): ?string
    {
        $key = $request->input('key');

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Read;
    }

    public function activityLogType(): ActivityLogType
    {
        return $this->effect();
    }

    public function type(): string
    {
        return 'api:POST /doctor/run';
    }

    public function activityLogAction(): string
    {
        return $this->type();
    }

    public function subject(): ?Model
    {
        return null;
    }

    public function activityLogSubject(): ?Model
    {
        return $this->subject();
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return [];
    }

    public function activityLogProperties(): array
    {
        return $this->properties();
    }

    public function description(): ?string
    {
        return null;
    }

    public function activityLogDescription(): ?string
    {
        return $this->description();
    }
}
