<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Data\Doctor\DoctorAppInstanceTarget;
use App\Data\Doctor\DoctorRunRequest;
use App\Data\Doctor\DoctorTargetScope;
use App\Enums\ActivityLogType;
use App\Exceptions\AppSelectionResolutionFailed;
use App\Exceptions\WorkspaceUnsupportedForProduction;
use App\Models\Node;
use App\Services\Doctor\DoctorAppInstanceTargetResolver;
use App\Services\Doctor\DoctorProgressReportFactory;
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
        NodeAccessAuthorizer $authorizer,
        ProgressEventStreamResponseFactory $streams,
        DoctorAppInstanceTargetResolver $appTargets,
        WorkspaceRoleGuard $workspaceRoleGuard,
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
        $key = $this->key($request);
        $dryRun = $request->boolean('dry_run');
        $this->activityKey = $key;
        $this->activityDryRun = $dryRun;

        $families = $this->families($request);
        $scopeFailure = $this->validateScope($request);

        if ($scopeFailure instanceof JsonResponse) {
            return $scopeFailure;
        }

        try {
            $appTarget = $appTargets->resolve(
                $this->scopeValue($request, 'app'),
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

        if ($appTarget instanceof DoctorAppInstanceTarget && $target->id !== $appTarget->node->id) {
            return response()->json([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => "App instance '{$appTarget->app->name}.{$appTarget->instance->name}' is not placed on node '{$target->name}'.",
                    'meta' => [
                        'field' => 'node',
                        'reason' => 'app_instance_node_mismatch',
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

        $scope = $appTarget instanceof DoctorAppInstanceTarget
            ? $appTarget->scope($this->scopeValue($request, 'workspace'))
            : DoctorTargetScope::from(
                $this->scopeValue($request, 'app'),
                $this->scopeValue($request, 'workspace'),
            );

        $failure = $validator->validate($families, $runner, $target, $scope);

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

        $issues = $this->issues($request);

        if ($this->wantsEventStream($request)) {
            return $this->stream(
                $streams,
                $runner,
                $progressReports,
                $target,
                $mode,
                $families,
                $issues,
                $key,
                $dryRun,
                $scope,
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
                    'doctor' => $doctor,
                ],
            ],
        ]);
    }

    /**
     * @param  list<string>  $families
     * @param  list<array<string, mixed>>|null  $issues
     */
    private function stream(
        ProgressEventStreamResponseFactory $streams,
        DoctorReportRunner $runner,
        DoctorProgressReportFactory $progressReports,
        Node $target,
        string $mode,
        array $families,
        ?array $issues,
        ?string $key,
        bool $dryRun,
        DoctorTargetScope $scope,
    ): StreamedResponse {
        return $streams->make(function (ProgressEventStreamEmitter $events) use (
            $runner,
            $progressReports,
            $target,
            $mode,
            $families,
            $issues,
            $key,
            $dryRun,
            $scope,
        ): void {
            $renderedFamilies = $families === [] ? $runner->categoriesForNode($target) : $families;
            $familyStatuses = $progressReports->familyStatuses($renderedFamilies);

            $events->stepEvent('__doctor_panel', 'running', 'Doctor queued', [
                'doctor' => $progressReports->report(
                    target: $target,
                    mode: $mode,
                    families: $renderedFamilies,
                    key: $key,
                    issues: [],
                    actions: [],
                    familyStatuses: $familyStatuses,
                    app: $scope->app,
                    workspace: $scope->workspace,
                    appInstance: $scope->appInstance,
                ),
            ]);
            $events->tree('Running Doctor', array_map(
                fn (string $family): array => [
                    'key' => $family,
                    'label' => "{$mode} {$family}",
                ],
                $renderedFamilies,
            ));

            foreach ($renderedFamilies as $family) {
                $familyStatuses[$family] = $mode === 'adopt' ? 'adopting' : 'restoring';
                $events->stepEvent($family, 'running', "{$mode} {$family}", [
                    'doctor' => $progressReports->report(
                        target: $target,
                        mode: $mode,
                        families: $renderedFamilies,
                        key: $key,
                        issues: [],
                        actions: [],
                        familyStatuses: $familyStatuses,
                        app: $scope->app,
                        workspace: $scope->workspace,
                        appInstance: $scope->appInstance,
                    ),
                ]);
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

            foreach ($renderedFamilies as $family) {
                $familyStatuses[$family] = 'done';
                $events->stepEvent($family, 'done', "{$family} {$mode} complete", [
                    'doctor' => $progressReports->report(
                        target: $target,
                        mode: $mode,
                        families: $renderedFamilies,
                        key: $key,
                        issues: $this->doctorEntries($doctor, 'issues'),
                        actions: $this->doctorEntries($doctor, 'actions'),
                        familyStatuses: $familyStatuses,
                        app: $scope->app,
                        workspace: $scope->workspace,
                        appInstance: $scope->appInstance,
                    ),
                ]);
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
     * @param  list<array<string, mixed>>  $issues
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
        $probe = $runner->probe($target, $families, $key, scope: $scope);
        $actions = $runner->apply($target, $mode, $issues);

        if ($runner->restoreRequiresVerification($mode, $key, $probe)) {
            return $runner->finalizeRestore($target, $families, $key, $scope, $actions);
        }

        return $runner->finalize($probe, $mode, $actions);
    }

    private function resolveTarget(
        Request $request,
        Node $caller,
        ?DoctorAppInstanceTarget $appTarget = null,
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
            $exception->errorCode === 'app.not_found' ? 404 : 422,
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

    public function activityLogType(): ActivityLogType
    {
        return $this->effect();
    }

    public function type(): string
    {
        return 'api:POST /doctor/fix';
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
        return array_filter(
            [
                'mode' => $this->activityMode,
                'key' => $this->activityKey,
                'dry_run' => $this->activityDryRun ? true : null,
            ],
            static fn (mixed $value): bool => $value !== null,
        );
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
