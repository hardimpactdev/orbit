<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Models\Node;
use App\Services\Doctor\DoctorProgressReportFactory;
use App\Services\Doctor\DoctorReportRunner;
use App\Services\Doctor\DoctorScopeValidator;
use App\Services\Doctor\DoctorValidationFailure;
use App\Support\Streaming\ProgressEventStreamEmitter;
use App\Support\Streaming\ProgressEventStreamResponseFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DoctorRunController implements Loggable
{
    public function __invoke(
        Request $request,
        DoctorReportRunner $runner,
        DoctorScopeValidator $validator,
        DoctorProgressReportFactory $progressReports,
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

        $families = $this->families($request);
        $key = $this->key($request);

        $scopeFailure = $this->validateScope($request);

        if ($scopeFailure instanceof JsonResponse) {
            return $scopeFailure;
        }

        if ($this->usesFleetScope($request)) {
            $failure = $validator->validate($families, $runner);

            if ($failure instanceof DoctorValidationFailure) {
                return response()->json([
                    'error' => [
                        'code' => $failure->code,
                        'message' => $failure->message,
                        'meta' => $failure->meta,
                    ],
                ], 422);
            }

            if ($this->wantsEventStream($request)) {
                return $this->streamFleet($streams, $runner, $families, $key);
            }

            $doctor = $runner->probeFleet(families: $families, key: $key);

            return response()->json([
                'success' => [
                    'data' => [
                        'doctor' => $doctor,
                    ],
                ],
            ]);
        }

        $target = $this->resolveTarget($request, $caller);

        if ($target === null) {
            return response()->json([
                'error' => [
                    'code' => 'scope_not_found',
                    'message' => 'Target node could not be resolved.',
                    'meta' => ['node' => $request->input('node')],
                ],
            ], 422);
        }

        $failure = $validator->validate($families, $runner, $target);

        if ($failure instanceof DoctorValidationFailure) {
            return response()->json([
                'error' => [
                    'code' => $failure->code,
                    'message' => $failure->message,
                    'meta' => $failure->meta,
                ],
            ], 422);
        }

        if ($this->wantsEventStream($request)) {
            return $this->stream($streams, $runner, $progressReports, $target, $families, $key);
        }

        $doctor = $runner->probe($target, families: $families, key: $key);

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
     */
    private function stream(
        ProgressEventStreamResponseFactory $streams,
        DoctorReportRunner $runner,
        DoctorProgressReportFactory $progressReports,
        Node $target,
        array $families,
        ?string $key,
    ): StreamedResponse {
        return $streams->make(function (ProgressEventStreamEmitter $events) use (
            $runner,
            $progressReports,
            $target,
            $families,
            $key,
        ): void {
            $renderedFamilies = $families === [] ? $runner->categoriesForNode($target) : $families;
            $familyStatuses = $progressReports->familyStatuses($renderedFamilies);
            /** @var list<array<string, mixed>> $issues */
            $issues = [];

            $events->stepEvent('__doctor_panel', 'running', 'Doctor queued', [
                'doctor' => $progressReports->report(
                    target: $target,
                    mode: 'verify',
                    families: $renderedFamilies,
                    key: $key,
                    issues: $issues,
                    actions: [],
                    familyStatuses: $familyStatuses,
                ),
            ]);
            $events->tree('Running Doctor', array_map(
                fn (string $family): array => [
                    'key' => $family,
                    'label' => "Check {$family}",
                ],
                $renderedFamilies,
            ));

            /** @param  list<array<string, mixed>>  $familyIssues */
            $onFamilyProgress = function (
                string $family,
                string $phase,
                array $familyIssues = [],
            ) use ($events, $progressReports, $target, $key, $renderedFamilies, &$familyStatuses, &$issues): void {
                if ($phase === 'running') {
                    $familyStatuses[$family] = 'checking';
                }

                if ($phase === 'done') {
                    $issues = [
                        ...$issues,
                        ...$familyIssues,
                    ];
                    $familyStatuses[$family] = 'done';
                }

                $events->stepEvent(
                    $family,
                    $phase,
                    $phase === 'running' ? "Checking {$family}" : "{$family} checked",
                    [
                        'doctor' => $progressReports->report(
                            target: $target,
                            mode: 'verify',
                            families: $renderedFamilies,
                            key: $key,
                            issues: $issues,
                            actions: [],
                            familyStatuses: $familyStatuses,
                        ),
                    ],
                );
            };

            $doctor = $runner->probe(
                $target,
                families: $families,
                key: $key,
                onFamilyProgress: $onFamilyProgress,
            );

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
     */
    private function streamFleet(
        ProgressEventStreamResponseFactory $streams,
        DoctorReportRunner $runner,
        array $families,
        ?string $key,
    ): StreamedResponse {
        return $streams->make(function (ProgressEventStreamEmitter $events) use ($runner, $families, $key): void {
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
                onNodeProgress: function (Node $node, string $phase) use ($events): void {
                    $events->stepEvent(
                        $node->name,
                        $phase,
                        $phase === 'running' ? "Checking {$node->name}" : "{$node->name} checked",
                    );
                },
            );

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

    private function resolveTarget(Request $request, Node $caller): ?Node
    {
        $name = $request->input('node');

        if (is_string($name) && $name !== '') {
            $target = Node::query()->where('name', $name)->first();

            return $target instanceof Node ? $target : null;
        }

        return $caller;
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
            $this->scopeValue($request, 'app') !== null ? 'app' : null,
            $this->scopeValue($request, 'workspace') !== null ? 'workspace' : null,
        ]));

        if ($conflicts === []) {
            return null;
        }

        return response()->json([
            'error' => [
                'code' => 'validation_failed',
                'message' => 'all cannot be combined with node, self, app, or workspace scope.',
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
