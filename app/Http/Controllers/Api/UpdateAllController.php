<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Contracts\ProgressReporter;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\ActivityLogType;
use App\Models\Node;
use App\Services\OrbitUpdater;
use App\Support\Streaming\ProgressEventStreamResponseFactory;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class UpdateAllController implements Loggable
{
    private const int REMOTE_UPDATE_CONCURRENCY = 4;

    private ?Node $activitySubject = null;

    public function __invoke(
        Request $request,
        OrbitUpdater $updater,
        ProgressEventStreamResponseFactory $streams,
    ): JsonResponse|StreamedResponse {
        if ($this->wantsEventStream($request)) {
            return $this->stream($request, $updater, $streams);
        }

        $authorized = $this->authorizeCaller($request);

        if ($authorized instanceof JsonResponse) {
            return $authorized;
        }

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

    private function stream(Request $request, OrbitUpdater $updater, ProgressEventStreamResponseFactory $streams): JsonResponse|StreamedResponse
    {
        $authorized = $this->authorizeCaller($request);

        if ($authorized instanceof JsonResponse) {
            return $authorized;
        }

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

    private function authorizeCaller(Request $request): ?JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        $this->activitySubject = $caller;

        return null;
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
        $nodes = Node::query()
            ->where('status', 'active')
            ->where('role', 'app')
            ->orderBy('name')
            ->get();

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

    private function updateLocalTarget(OrbitUpdater $updater, string $target, ?ProgressReporter $reporter): ProcessResult
    {
        $reporter?->stepProgress($target, 'pulling_source', $this->stageMessage('pulling_source', $target));
        $result = $updater->pullSource();

        if (! $result->successful()) {
            $reporter?->stepFail($target, trim($result->errorOutput() ?: $result->output()));

            return $result;
        }

        $reporter?->stepProgress($target, 'installing_dependencies', $this->stageMessage('installing_dependencies', $target));
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

    private function updateRemoteTarget(OrbitUpdater $updater, Node $node, ?ProgressReporter $reporter): RemoteShellResult
    {
        if (! $reporter instanceof ProgressReporter) {
            return $updater->updateRemote($node);
        }

        $reporter->stepProgress($node->name, 'pulling_source', $this->stageMessage('pulling_source', $node->name));
        $result = $updater->pullRemoteSource($node);

        if (! $result->successful()) {
            $reporter->stepFail($node->name, trim($result->errorOutput() ?: $result->output()));

            return $result;
        }

        $reporter->stepProgress($node->name, 'installing_dependencies', $this->stageMessage('installing_dependencies', $node->name));
        $result = $updater->installRemoteDependencies($node);

        if (! $result->successful()) {
            $reporter->stepFail($node->name, trim($result->errorOutput() ?: $result->output()));

            return $result;
        }

        $reporter->stepProgress($node->name, 'running_migrations', $this->stageMessage('running_migrations', $node->name));
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
        if ($nodes->isEmpty()) {
            return [];
        }

        if (! $this->canRunRemoteUpdateWorkers()) {
            return $this->updateRemoteTargetsSequentially($updater, $nodes, $reporter);
        }

        return $this->updateRemoteTargetsConcurrently($updater, $nodes, $reporter);
    }

    private function canRunRemoteUpdateWorkers(): bool
    {
        return function_exists('pcntl_fork')
            && function_exists('stream_socket_pair');
    }

    /**
     * @param  Collection<int, Node>  $nodes
     * @return list<array<string, mixed>>
     */
    private function updateRemoteTargetsSequentially(OrbitUpdater $updater, Collection $nodes, ?ProgressReporter $reporter): array
    {
        $updates = [];

        foreach ($nodes as $node) {
            $updates[] = $this->remoteTargetUpdate($node, $this->updateRemoteTarget($updater, $node, $reporter));
        }

        return $updates;
    }

    /**
     * @param  Collection<int, Node>  $nodes
     * @return list<array<string, mixed>>
     */
    private function updateRemoteTargetsConcurrently(OrbitUpdater $updater, Collection $nodes, ?ProgressReporter $reporter): array
    {
        $nodeList = array_values($nodes->all());
        $workers = [];
        $updatesByIndex = [];
        $nextIndex = 0;

        while ($nextIndex < count($nodeList) || $workers !== []) {
            while (count($workers) < self::REMOTE_UPDATE_CONCURRENCY && $nextIndex < count($nodeList)) {
                $node = $nodeList[$nextIndex];
                $worker = $this->startRemoteUpdateWorker($updater, $node, $nextIndex);

                if ($worker === null) {
                    $updatesByIndex[$nextIndex] = $this->remoteTargetUpdate($node, $this->updateRemoteTarget($updater, $node, $reporter));
                    $nextIndex++;

                    continue;
                }

                $workers[$nextIndex] = [
                    'node' => $node,
                    'pipe' => $worker['pipe'],
                    'pid' => $worker['pid'],
                    'buffer' => '',
                ];
                $nextIndex++;
            }

            foreach (array_keys($workers) as $index) {
                $this->drainRemoteUpdateWorkerEvents(
                    $workers[$index]['pipe'],
                    $workers[$index]['buffer'],
                    $reporter,
                    $updatesByIndex,
                );

                $wait = pcntl_waitpid($workers[$index]['pid'], $status, WNOHANG);

                if ($wait <= 0) {
                    continue;
                }

                $this->drainRemoteUpdateWorkerEvents(
                    $workers[$index]['pipe'],
                    $workers[$index]['buffer'],
                    $reporter,
                    $updatesByIndex,
                );
                fclose($workers[$index]['pipe']);

                if (! isset($updatesByIndex[$index])) {
                    $this->markRemoteWorkerFailure($workers[$index]['node'], $index, $reporter, $updatesByIndex);
                }

                unset($workers[$index]);
            }

            if ($workers !== []) {
                usleep(50_000);
            }
        }

        ksort($updatesByIndex);

        return array_values($updatesByIndex);
    }

    /**
     * @return array{pipe: resource, pid: int}|null
     */
    private function startRemoteUpdateWorker(OrbitUpdater $updater, Node $node, int $index): ?array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($pair === false) {
            return null;
        }

        $pid = pcntl_fork();

        if ($pid === -1) {
            fclose($pair[0]);
            fclose($pair[1]);

            return null;
        }

        if ($pid === 0) {
            fclose($pair[0]);
            $this->runRemoteUpdateWorker($updater, $node, $pair[1], $index);
            fclose($pair[1]);

            if (function_exists('posix_kill')) {
                posix_kill(getmypid(), SIGKILL);
            }

            exit(0);
        }

        fclose($pair[1]);
        stream_set_blocking($pair[0], false);

        return ['pipe' => $pair[0], 'pid' => $pid];
    }

    private function runRemoteUpdateWorker(OrbitUpdater $updater, Node $node, mixed $pipe, int $index): void
    {
        foreach (['pulling_source', 'installing_dependencies', 'running_migrations'] as $stage) {
            $this->writeRemoteUpdateWorkerEvent($pipe, [
                'type' => 'stage',
                'index' => $index,
                'key' => $node->name,
                'stage' => $stage,
            ]);

            $result = match ($stage) {
                'pulling_source' => $updater->pullRemoteSource($node),
                'installing_dependencies' => $updater->installRemoteDependencies($node),
                'running_migrations' => $updater->runRemoteMigrations($node),
            };

            if (! $result->successful()) {
                $this->writeRemoteUpdateWorkerEvent($pipe, [
                    'type' => 'fail',
                    'index' => $index,
                    'key' => $node->name,
                    'node' => $node->name,
                    'role' => $node->role,
                    'output' => trim($result->errorOutput() ?: $result->output()),
                ]);

                return;
            }
        }

        $this->writeRemoteUpdateWorkerEvent($pipe, [
            'type' => 'done',
            'index' => $index,
            'key' => $node->name,
            'node' => $node->name,
            'role' => $node->role,
        ]);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function writeRemoteUpdateWorkerEvent(mixed $pipe, array $event): void
    {
        fwrite($pipe, json_encode($event, JSON_THROW_ON_ERROR)."\n");
    }

    /**
     * @param  array<int, array<string, mixed>>  $updatesByIndex
     */
    private function drainRemoteUpdateWorkerEvents(
        mixed $pipe,
        string &$buffer,
        ?ProgressReporter $reporter,
        array &$updatesByIndex,
    ): void {
        $data = stream_get_contents($pipe);

        if (is_string($data) && $data !== '') {
            $buffer .= $data;
        }

        while (($position = strpos($buffer, "\n")) !== false) {
            $line = substr($buffer, 0, $position);
            $buffer = substr($buffer, $position + 1);

            if ($line === '') {
                continue;
            }

            try {
                /** @var array<string, mixed> $event */
                $event = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            $this->applyRemoteUpdateWorkerEvent($event, $reporter, $updatesByIndex);
        }
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<int, array<string, mixed>>  $updatesByIndex
     */
    private function applyRemoteUpdateWorkerEvent(
        array $event,
        ?ProgressReporter $reporter,
        array &$updatesByIndex,
    ): void {
        $type = is_string($event['type'] ?? null) ? $event['type'] : null;
        $key = is_string($event['key'] ?? null) ? $event['key'] : null;
        $index = is_int($event['index'] ?? null) ? $event['index'] : null;

        if ($type === null || $key === null || $index === null) {
            return;
        }

        if ($type === 'stage') {
            $stage = is_string($event['stage'] ?? null) ? $event['stage'] : null;

            if ($stage !== null) {
                $reporter?->stepProgress($key, $stage, $this->stageMessage($stage, $key));
            }

            return;
        }

        $node = is_string($event['node'] ?? null) ? $event['node'] : $key;
        $role = is_string($event['role'] ?? null) ? $event['role'] : null;

        if ($type === 'done') {
            $reporter?->stepDone($key, $this->stageMessage('done', $key));
            $updatesByIndex[$index] = [
                'target' => $key,
                'node' => $node,
                'role' => $role,
                'status' => 'completed',
            ];

            return;
        }

        if ($type === 'fail') {
            $output = is_string($event['output'] ?? null) ? $event['output'] : '';
            $reporter?->stepFail($key, $output !== '' ? $output : 'Failed');
            $updatesByIndex[$index] = [
                'target' => $key,
                'node' => $node,
                'role' => $role,
                'status' => 'failed',
                'output' => $output,
            ];
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $updatesByIndex
     */
    private function markRemoteWorkerFailure(Node $node, int $index, ?ProgressReporter $reporter, array &$updatesByIndex): void
    {
        $output = 'Worker exited without reporting a result.';
        $reporter?->stepFail($node->name, $output);
        $updatesByIndex[$index] = [
            'target' => $node->name,
            'node' => $node->name,
            'role' => $node->role,
            'status' => 'failed',
            'output' => $output,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function remoteTargetUpdate(Node $node, RemoteShellResult $result): array
    {
        return [
            'target' => $node->name,
            'node' => $node->name,
            'role' => $node->role,
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
        $node = Node::query()
            ->where('role', 'gateway')
            ->where('status', 'active')
            ->first();

        if ($node instanceof Node) {
            return [
                'target' => $node->name,
                'node' => $node->name,
                'role' => $node->role,
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

    private function authorizationFailed(string $message): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'authorization_failed',
                'message' => $message,
                'meta' => [],
            ],
        ], 403);
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
