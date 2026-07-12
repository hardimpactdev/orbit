<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Contracts\ProgressReporter;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\ActivityLogType;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Operations\FleetUpdateTargetSelector;
use App\Services\OrbitUpdater;
use App\Support\Streaming\ProgressEventStreamResponseFactory;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[RequiresPermission('*', servingNode: ServingNode::Gateway)]
final class UpdateAllController implements Loggable
{
    private ?Node $activitySubject = null;

    public function __invoke(
        Request $request,
        OrbitUpdater $updater,
        ProgressEventStreamResponseFactory $streams,
    ): JsonResponse|StreamedResponse {
        if ($this->wantsEventStream($request)) {
            return $this->stream($request, $updater, $streams);
        }

        $this->captureActivitySubject($request);

        $result = $this->runUpdateAll($updater);

        if (! $result['local_successful']) {
            return response()->json([
                'error' => [
                    'code' => 'local_update_failed',
                    'message' => 'Failed to update local Orbit checkout.',
                    'data' => [
                        'output' => $result['output'],
                    ],
                    'meta' => ['failed_step' => 'local_checkout'],
                ],
            ], 422);
        }

        return response()->json([
            'success' => [
                'data' => [
                    'updates' => $result['updates'],
                ],
                'meta' => [
                    'summary' => $result['summary'],
                ],
            ],
        ]);
    }

    private function stream(
        Request $request,
        OrbitUpdater $updater,
        ProgressEventStreamResponseFactory $streams,
    ): StreamedResponse {
        $this->captureActivitySubject($request);

        return $streams->make(function ($emitter) use ($updater): void {
            $result = $this->runUpdateAll($updater, app(ProgressReporter::class));

            if (! $result['local_successful']) {
                $emitter->error('Failed to update local Orbit checkout.', 1, [
                    'code' => 'local_update_failed',
                    'output' => $result['output'],
                    'updates' => $result['updates'],
                    'summary' => $result['summary'],
                ]);

                return;
            }

            if ($result['summary']['failed'] > 0) {
                $emitter->error('One or more Orbit installations failed to update.', 1, [
                    'code' => 'remote_update_failed',
                    'updates' => $result['updates'],
                    'summary' => $result['summary'],
                ]);

                return;
            }

            $emitter->complete(0, [
                'updates' => $result['updates'],
                'summary' => $result['summary'],
            ]);
        });
    }

    private function wantsEventStream(Request $request): bool
    {
        return in_array('text/event-stream', $request->getAcceptableContentTypes(), true);
    }

    private function captureActivitySubject(Request $request): void
    {
        /** @var mixed $caller */
        $caller = $request->user();

        $this->activitySubject = $caller instanceof Node ? $caller : null;
    }

    /**
     * @return array{
     *     updates: list<array<string, mixed>>,
     *     summary: array{total: int, completed: int, failed: int},
     *     local_successful: bool,
     *     output: string,
     * }
     */
    private function runUpdateAll(OrbitUpdater $updater, ?ProgressReporter $reporter = null): array
    {
        $nodes = app(FleetUpdateTargetSelector::class)->workloadNodes();

        $localTarget = $this->localGatewayTarget();
        $updates = [];

        if ($reporter instanceof ProgressReporter) {
            $reporter->tree('Updating Orbit nodes', [
                [
                    'key' => $localTarget['target'],
                    'label' => $this->stageMessage('pulling_source', $localTarget['target']),
                ],
                ...$nodes->map(fn (Node $node): array => [
                    'key' => $node->name,
                    'label' => $this->stageMessage('pulling_source', $node->name),
                ])->all(),
            ]);
        }

        $localResult = $this->updateLocalTarget($updater, $localTarget['target'], $reporter);
        $output = trim($localResult->errorOutput() ?: $localResult->output());
        $updates[] = [
            ...$localTarget,
            'status' => $localResult->successful() ? 'completed' : 'failed',
        ];

        if (! $localResult->successful()) {
            $reporter?->stepFail($localTarget['target'], $output !== '' ? $output : 'Failed');

            return [
                'updates' => $updates,
                'summary' => $this->summary($updates),
                'local_successful' => false,
                'output' => $output,
            ];
        }

        $updates = array_merge(
            $updates,
            $this->updateRemoteTargets($updater, $nodes, $reporter),
        );

        return [
            'updates' => $updates,
            'summary' => $this->summary($updates),
            'local_successful' => true,
            'output' => '',
        ];
    }

    private function updateLocalTarget(
        OrbitUpdater $updater,
        string $target,
        ?ProgressReporter $reporter,
    ): ProcessResult {
        $reporter?->stepProgress($target, 'pulling_source', $this->stageMessage('pulling_source', $target));
        $result = $updater->pullSource();

        if (! $result->successful()) {
            $reporter?->stepFail($target, trim($result->errorOutput() ?: $result->output()));

            return $result;
        }

        $reporter?->stepProgress(
            $target,
            'installing_dependencies',
            $this->stageMessage('installing_dependencies', $target),
        );
        $result = $updater->installDependencies();

        if (! $result->successful()) {
            $reporter?->stepFail($target, trim($result->errorOutput() ?: $result->output()));

            return $result;
        }

        $reporter?->stepProgress($target, 'running_migrations', $this->stageMessage('running_migrations', $target));
        $result = $updater->runMigrations();

        if (! $result->successful()) {
            $reporter?->stepFail($target, trim($result->errorOutput() ?: $result->output()));

            return $result;
        }

        $reporter?->stepDone($target, $this->stageMessage('done', $target));

        return $result;
    }

    private function updateRemoteTarget(
        OrbitUpdater $updater,
        Node $node,
        ?ProgressReporter $reporter,
    ): RemoteShellResult {
        if (! $reporter instanceof ProgressReporter) {
            return $updater->updateRemote($node);
        }

        $reporter->stepProgress($node->name, 'pulling_source', $this->stageMessage('pulling_source', $node->name));
        $result = $updater->pullRemoteSource($node);

        if (! $result->successful()) {
            $reporter->stepFail($node->name, trim($result->errorOutput() ?: $result->output()));

            return $result;
        }

        $reporter->stepProgress(
            $node->name,
            'installing_dependencies',
            $this->stageMessage('installing_dependencies', $node->name),
        );
        $result = $updater->installRemoteDependencies($node);

        if (! $result->successful()) {
            $reporter->stepFail($node->name, trim($result->errorOutput() ?: $result->output()));

            return $result;
        }

        $reporter->stepProgress(
            $node->name,
            'running_migrations',
            $this->stageMessage('running_migrations', $node->name),
        );
        $result = $updater->runRemoteMigrations($node);

        if (! $result->successful()) {
            $reporter->stepFail($node->name, trim($result->errorOutput() ?: $result->output()));

            return $result;
        }

        $reporter->stepDone($node->name, $this->stageMessage('done', $node->name));

        return $result;
    }

    /**
     * @param  Collection<int, Node>  $nodes
     * @return list<array<string, mixed>>
     */
    private function updateRemoteTargets(OrbitUpdater $updater, Collection $nodes, ?ProgressReporter $reporter): array
    {
        return $this->updateRemoteTargetsSequentially($updater, $nodes, $reporter);
    }

    /**
     * @param  Collection<int, Node>  $nodes
     * @return list<array<string, mixed>>
     */
    private function updateRemoteTargetsSequentially(
        OrbitUpdater $updater,
        Collection $nodes,
        ?ProgressReporter $reporter,
    ): array {
        $updates = [];

        foreach ($nodes as $node) {
            try {
                $updates[] = $this->remoteTargetUpdate($node, $this->updateRemoteTarget($updater, $node, $reporter));
            } catch (\Throwable $exception) {
                $output = $exception->getMessage() !== '' ? $exception->getMessage() : 'Agent update failed.';
                $reporter?->stepFail($node->name, $output);
                $updates[] = [
                    'target' => $node->name,
                    'node' => $node->name,
                    'role' => $node->displayRole(),
                    'status' => 'failed',
                    'output' => $output,
                ];
            }
        }

        return $updates;
    }

    /**
     * @return array<string, mixed>
     */
    private function remoteTargetUpdate(Node $node, RemoteShellResult $result): array
    {
        return [
            'target' => $node->name,
            'node' => $node->name,
            'role' => $node->displayRole(),
            'status' => $result->successful() ? 'completed' : 'failed',
            ...($result->successful() ? [] : ['output' => trim($result->errorOutput() ?: $result->output())]),
        ];
    }

    private function stageMessage(string $stage, string $target): string
    {
        return match ($stage) {
            'pulling_source' => "Pulling source - {$target}",
            'installing_dependencies' => "Installing dependencies - {$target}",
            'running_migrations' => "Running migrations - {$target}",
            'done' => "Done - {$target}",
            default => $target,
        };
    }

    /**
     * @return array{target: string, node: string, role: string}
     */
    private function localGatewayTarget(): array
    {
        $node = app(NodeRoleAssignments::class)->activeGatewayNodeQuery()->first();

        if ($node instanceof Node) {
            return [
                'target' => $node->name,
                'node' => $node->name,
                'role' => $node->displayRole(),
            ];
        }

        return [
            'target' => 'gateway',
            'node' => 'gateway',
            'role' => 'gateway',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $updates
     * @return array{total: int, completed: int, failed: int}
     */
    private function summary(array $updates): array
    {
        return [
            'total' => count($updates),
            'completed' => count(array_filter($updates, fn (array $update): bool => $update['status'] === 'completed')),
            'failed' => count(array_filter($updates, fn (array $update): bool => $update['status'] === 'failed')),
        ];
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    public function type(): string
    {
        return 'api:POST /update/all';
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
        return [];
    }

    public function description(): ?string
    {
        return null;
    }
}
