<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Data\Doctor\DoctorInstanceTarget;
use App\Data\Doctor\DoctorIssue;
use App\Data\Doctor\DoctorRunRequest;
use App\Data\Doctor\DoctorTargetScope;
use App\Enums\ActivityLogType;
use App\Exceptions\AppSelectionResolutionFailed;
use App\Exceptions\DoctorIssueIdentityMismatch;
use App\Exceptions\DoctorUncataloguedIssueException;
use App\Exceptions\WorkspaceUnsupportedForProduction;
use App\Models\Node;
use App\Services\Doctor\DoctorInstanceTargetResolver;
use App\Services\Doctor\DoctorIssueFactory;
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

final class DoctorFixController implements Loggable
{
    private string $activityMode = 'restore';

    private ?string $activityKey = null;

    private bool $activityDryRun = false;

    public function __invoke(
        Request $request,
        DoctorReportRunner $runner,
        DoctorScopeValidator $validator,
        DoctorProgressReportFactory $progressReports,
        DoctorPublicVocabulary $vocabulary,
        NodeAccessAuthorizer $authorizer,
        ProgressEventStreamResponseFactory $streams,
        DoctorInstanceTargetResolver $appTargets,
        WorkspaceRoleGuard $workspaceRoleGuard,
        DoctorIssueFactory $issueFactory,
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

        $mode = $this->mode($request);

        if ($mode === null) {
            return response()->json([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'Doctor fix mode must be restore or adopt.',
                    'meta' => ['fields' => ['mode']],
                ],
            ], 422);
        }

        $this->activityMode = $mode;
        $key = $vocabulary->internalKey($this->key($request));
        $dryRun = $request->boolean('dry_run');
        $this->activityKey = $key;
        $this->activityDryRun = $dryRun;

        $families = $vocabulary->internalFamilies($this->families($request));
        $scopeFailure = $this->validateScope($request);

        if ($scopeFailure instanceof JsonResponse) {
            return $scopeFailure;
        }

        try {
            $appTarget = $appTargets->resolve(
                $this->scopeValue($request, 'instance'),
                $caller,
                $mode === 'adopt' ? 'doctor:adopt' : 'doctor:restore',
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

        $authorization = $this->authorizeDoctorFix($authorizer, $caller, $target, $mode);

        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

        $scope = $appTarget instanceof DoctorInstanceTarget
            ? $appTarget->scope($this->scopeValue($request, 'workspace'))
            : DoctorTargetScope::from(
                $this->scopeValue($request, 'instance'),
                $this->scopeValue($request, 'workspace'),
            );

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
            $workspaceRoleGuard->ensureDoctorRequestDoesNotCrossProductionBoundary(
                caller: $caller,
                target: $target,
                families: $families === [] ? $runner->categoriesForNode($target) : $families,
                hasWorkspaceScope: $scope->workspace !== null,
            );
        } catch (WorkspaceUnsupportedForProduction $exception) {
            return $this->workspaceUnsupportedForProduction($exception);
        }

        $issueValues = $vocabulary->internalIssues($this->issues($request));

        try {
            $issues = $issueValues === null
                ? null
                : array_map(
                    $issueFactory->fromClientArray(...),
                    $issueValues,
                );
        } catch (DoctorIssueIdentityMismatch|DoctorUncataloguedIssueException $exception) {
            return response()->json([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'Selected Doctor issues do not match the Doctor issue catalog.',
                    'meta' => [
                        'fields' => ['issues'],
                        'reason' => $exception->getMessage(),
                    ],
                ],
            ], 422);
        }

        if ($this->wantsEventStream($request)) {
            return $this->stream(
                $streams,
                $runner,
                $progressReports,
                $vocabulary,
                $target,
                $mode,
                $families,
                $issues,
                $key,
                $dryRun,
                $scope,
                $this->wantsCompactProgress($request),
            );
        }

        $doctor = $issues === null || $dryRun
            ? $runner->run(
                $target,
                mode: $mode,
                families: $families,
                request: new DoctorRunRequest($key, $dryRun, $scope),
            )
            : $this->applySelectedIssues($runner, $target, $mode, $families, $issues, $key, $scope);

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
     * @param  list<DoctorIssue>|null  $issues
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
        string $mode,
        array $families,
        ?array $issues,
        ?string $key,
        bool $dryRun,
        DoctorTargetScope $scope,
        bool $compactProgress = false,
    ): StreamedResponse {
        return $streams->make(function (ProgressEventStreamEmitter $events) use (
            $runner,
            $progressReports,
            $vocabulary,
            $target,
            $mode,
            $families,
            $issues,
            $key,
            $dryRun,
            $scope,
            $compactProgress,
        ): void {
            $renderedFamilies = $families === [] ? $runner->categoriesForNode($target) : $families;
            $familyStatuses = $progressReports->familyStatuses($renderedFamilies);

            $events->stepEvent(
                '__doctor_panel',
                'running',
                'Doctor queued',
                $compactProgress
                    ? ['compact_progress' => true]
                    : [
                        'doctor' => $vocabulary->publicReport($progressReports->report(
                            target: $target,
                            mode: $mode,
                            families: $renderedFamilies,
                            key: $key,
                            issues: [],
                            actions: [],
                            familyStatuses: $familyStatuses,
                            app: $scope->app,
                            workspace: $scope->workspace,
                            instance: $scope->instance,
                        )),
                    ],
            );
            $events->tree('Running Doctor', array_map(
                fn (string $family): array => [
                    'key' => $vocabulary->publicFamily($family),
                    'label' => "{$mode} {$vocabulary->publicFamily($family)}",
                ],
                $renderedFamilies,
            ));

            foreach ($renderedFamilies as $family) {
                $familyStatuses[$family] = $mode === 'adopt' ? 'adopting' : 'restoring';
                $publicFamily = $vocabulary->publicFamily($family);
                $events->stepEvent(
                    $publicFamily,
                    'running',
                    "{$mode} {$publicFamily}",
                    $compactProgress
                        ? [
                            'compact_progress' => true,
                            'family' => $publicFamily,
                            'phase' => 'running',
                        ]
                        : [
                            'doctor' => $vocabulary->publicReport($progressReports->report(
                                target: $target,
                                mode: $mode,
                                families: $renderedFamilies,
                                key: $key,
                                issues: [],
                                actions: [],
                                familyStatuses: $familyStatuses,
                                app: $scope->app,
                                workspace: $scope->workspace,
                                instance: $scope->instance,
                            )),
                        ],
                );
            }

            $doctor = $issues === null || $dryRun
                ? $runner->run(
                    $target,
                    mode: $mode,
                    families: $families,
                    request: new DoctorRunRequest(
                        $key,
                        $dryRun,
                        $scope,
                    ),
                )
                : $this->applySelectedIssues(
                    $runner,
                    $target,
                    $mode,
                    $families,
                    $issues,
                    $key,
                    $scope,
                );
            $doctor = $vocabulary->publicReport($doctor);

            foreach ($renderedFamilies as $family) {
                $familyStatuses[$family] = 'done';
                $publicFamily = $vocabulary->publicFamily($family);
                $events->stepEvent(
                    $publicFamily,
                    'done',
                    "{$publicFamily} {$mode} complete",
                    $compactProgress
                        ? [
                            'compact_progress' => true,
                            'family' => $publicFamily,
                            'phase' => 'done',
                        ]
                        : [
                            'doctor' => $vocabulary->publicReport($progressReports->report(
                                target: $target,
                                mode: $mode,
                                families: $renderedFamilies,
                                key: $key,
                                issues: $this->doctorEntries($doctor, 'issues'),
                                actions: $this->doctorEntries($doctor, 'actions'),
                                familyStatuses: $familyStatuses,
                                app: $scope->app,
                                workspace: $scope->workspace,
                                instance: $scope->instance,
                            )),
                        ],
                );
            }

            if (($doctor['healthy'] ?? false) === true || $dryRun) {
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
     * @param  array<string, mixed>  $doctor
     * @return list<array<string, mixed>>
     */
    private function doctorEntries(array $doctor, string $key): array
    {
        $entries = $doctor[$key] ?? [];

        if (! is_array($entries)) {
            return [];
        }

        return array_values(array_filter($entries, is_array(...)));
    }

    /**
     * @param  list<string>  $families
     * @param  list<DoctorIssue>  $issues
     * @return array<string, mixed>
     */
    private function applySelectedIssues(
        DoctorReportRunner $runner,
        Node $target,
        string $mode,
        array $families,
        array $issues,
        ?string $key,
        DoctorTargetScope $scope,
    ): array {
        $actions = $runner->apply($target, $mode, $issues);

        return $runner->finalizeResolution(
            $target,
            $mode,
            $actions,
            $families,
            new DoctorRunRequest($key, dryRun: false, scope: $scope),
        );
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

    private function validateScope(Request $request): ?JsonResponse
    {
        $node = $this->scopeValue($request, 'node');

        if ($node !== null && strtolower($node) === 'all') {
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

        return response()->json([
            'error' => [
                'code' => 'validation_failed',
                'message' => 'Fleet doctor runs are verify-only; resolution modes require a single target node.',
                'meta' => ['field' => 'all'],
            ],
        ], 422);
    }

    private function scopeValue(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function authorizeDoctorFix(
        NodeAccessAuthorizer $authorizer,
        Node $caller,
        Node $target,
        string $mode,
    ): ?JsonResponse {
        $permission = $mode === 'adopt' ? 'doctor:adopt' : 'doctor:restore';
        $result = $authorizer->authorize($caller, $target, $permission);

        if ($result->allowed) {
            return null;
        }

        return $this->authorizationFailed($target, $permission, $result, $mode);
    }

    private function authorizationFailed(
        Node $target,
        string $permission,
        AuthorizationResult $result,
        string $mode,
    ): JsonResponse {
        return response()->json([
            'error' => [
                'code' => 'authorization_failed',
                'message' => "This node is not authorized for '{$permission}' on '{$target->name}'.",
                'meta' => [
                    'reason' => $result->reason,
                    'missing_permission' => $result->missingPermission,
                    'serving_node' => $target->name,
                    'mode' => $mode,
                ],
            ],
        ], 403);
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

    private function mode(Request $request): ?string
    {
        $mode = $request->input('mode');

        return is_string($mode) && in_array($mode, ['restore', 'adopt'], true) ? $mode : null;
    }

    private function wantsCompactProgress(Request $request): bool
    {
        return $request->boolean('compact_progress');
    }

    private function wantsEventStream(Request $request): bool
    {
        return in_array('text/event-stream', $request->getAcceptableContentTypes(), true);
    }

    private function key(Request $request): ?string
    {
        $key = $request->input('key');

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function issues(Request $request): ?array
    {
        if (! $request->has('issues')) {
            return null;
        }

        $issues = $request->input('issues');

        if (! is_array($issues)) {
            return [];
        }

        return array_values(array_filter($issues, is_array(...)));
    }

    public function effect(): ActivityLogType
    {
        if ($this->activityDryRun) {
            return ActivityLogType::Read;
        }

        return ActivityLogType::Write;
    }

    public function type(): string
    {
        return 'api:POST /doctor/fix';
    }

    public function subject(): ?Model
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return array_filter(
            [
                'mode' => $this->activityMode,
                'key' => $this->activityKey,
                'dry_run' => $this->activityDryRun ? true : null,
            ],
            static fn (mixed $value): bool => $value !== null,
        );
    }

    public function description(): ?string
    {
        return null;
    }
}
