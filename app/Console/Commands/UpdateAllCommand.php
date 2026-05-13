<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Concerns\LogsCommandActivity;
use App\Contracts\Loggable;
use App\Contracts\UpdateAllGatewayStream;
use App\Data\RemoteShell\RemoteShellResult;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Operations\UpdateAllRequest;
use App\Http\Gateway\Responses\Operations\UpdateAllResponse;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Services\OrbitUpdater;
use App\Support\Cli\UpdateAllProgress;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

#[Signature('update:all {--json : Output as JSON}')]
#[Description('Update this Orbit checkout and every active registered node')]
class UpdateAllCommand extends Command implements Loggable
{
    use LogsCommandActivity;

    /**
     * @var list<array{target: string, node: string|null, role: string|null}>
     */
    private array $targets = [];

    /**
     * @var array{total: int, completed: int, failed: int}|null
     */
    private ?array $activitySummary = null;

    private ?string $activityStatus = null;

    private ?string $activityFailedStep = null;

    public function handle(OrbitUpdater $updater, UpdateAllGatewayStream $gatewayStream): int
    {
        $this->bootActivityLog();

        try {
            return $this->executeUpdateAll($updater, $gatewayStream);
        } finally {
            $this->finishActivityLog();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return array_filter([
            'scope' => 'fleet',
            'status' => $this->activityStatus,
            'summary' => $this->activitySummary,
            'targets' => $this->targets,
            'failed_step' => $this->activityFailedStep,
        ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    public function description(): ?string
    {
        return match ($this->activityStatus) {
            'completed' => 'Orbit installations updated',
            'failed' => 'One or more Orbit installations failed to update',
            default => 'Orbit fleet update attempted',
        };
    }

    private function executeUpdateAll(OrbitUpdater $updater, UpdateAllGatewayStream $gatewayStream): int
    {
        $callerRole = (bool) config('orbit.is_gateway', false) ? 'gateway' : 'control';

        if ($callerRole === 'control' && ! $this->hasConfiguredGateway()) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to update the fleet.',
                meta: [],
            );
        }

        if ($callerRole === 'control') {
            return $this->executeControlPath($updater, $gatewayStream);
        }

        return $this->executeGatewayPath($updater);
    }

    private function executeControlPath(OrbitUpdater $updater, UpdateAllGatewayStream $gatewayStream): int
    {
        if (! $this->wantsJson()) {
            return $this->executeControlHumanPath($updater, $gatewayStream);
        }

        $updates = [];
        $localResult = $updater->updateLocal();

        $updates[] = [
            'target' => 'local',
            'node' => null,
            'role' => null,
            'status' => $localResult->successful() ? 'completed' : 'failed',
        ];

        if (! $localResult->successful()) {
            $this->captureActivitySummary($updates, 'failed', 'local_checkout');

            return $this->failCommand(
                code: 'local_update_failed',
                message: 'Failed to update local Orbit checkout.',
                data: ['output' => trim($localResult->errorOutput() ?: $localResult->output())],
                meta: ['failed_step' => 'local_checkout'],
            );
        }

        try {
            /** @var UpdateAllResponse $dto */
            $dto = app(GatewayConnector::class)
                ->send(new UpdateAllRequest)
                ->dto();
        } catch (GatewayApiException $e) {
            $errorData = $e->errorData();
            $updates = array_merge($updates, $errorData['updates'] ?? []);
            $errorData['updates'] = $updates;

            $this->captureActivitySummary($updates, 'failed', 'remote_update');

            return $this->failCommand(
                code: $e->errorCode() ?? 'gateway_unavailable',
                message: $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'Gateway connection is required to update the fleet.',
                meta: $e->errorMeta(),
                data: $errorData,
            );
        } catch (Throwable) {
            $this->captureActivitySummary($updates, 'failed', 'remote_update');

            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to update the fleet.',
                meta: [],
            );
        }

        $updates = array_merge($updates, $dto->updates);

        $completed = count(array_filter($updates, fn (array $u): bool => $u['status'] === 'completed'));
        $failed = count(array_filter($updates, fn (array $u): bool => $u['status'] === 'failed'));

        if ($failed > 0) {
            $this->captureActivitySummary($updates, 'failed', 'remote_update');

            return $this->failCommand(
                code: 'remote_update_failed',
                message: 'One or more Orbit installations failed to update.',
                data: ['updates' => $updates],
                meta: [
                    'summary' => [
                        'total' => count($updates),
                        'completed' => $completed,
                        'failed' => $failed,
                    ],
                ],
            );
        }

        $this->captureActivitySummary($updates, 'completed');

        return $this->respondSuccess($updates);
    }

    private function executeControlHumanPath(OrbitUpdater $updater, UpdateAllGatewayStream $gatewayStream): int
    {
        if (! function_exists('pcntl_fork') || ! function_exists('stream_socket_pair')) {
            return $this->executeControlHumanPathSequential($updater, $gatewayStream);
        }

        $streamWorker = $this->startGatewayStreamWorker($gatewayStream);

        if ($streamWorker === null) {
            return $this->executeControlHumanPathSequential($updater, $gatewayStream);
        }

        ['pipe' => $streamPipe, 'pid' => $streamPid] = $streamWorker;
        stream_set_blocking($streamPipe, false);

        $streamBuffer = '';
        $queuedStreamEvents = [];
        $remoteTargets = [];
        $streamTerminal = null;

        while ($remoteTargets === [] && $streamTerminal === null) {
            $this->drainGatewayStreamEvents($streamPipe, $streamBuffer, function (array $event) use (&$remoteTargets, &$queuedStreamEvents, &$streamTerminal): void {
                $queuedStreamEvents[] = $event;

                if (($event['event'] ?? null) === 'tree') {
                    $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
                    $remoteTargets = $this->remoteTargetsFromStreamPayload($payload);
                }

                if (in_array($event['event'] ?? null, ['complete', 'error', 'exception', 'result'], true)) {
                    $streamTerminal = $event;
                }
            });

            if (pcntl_waitpid($streamPid, $status, WNOHANG) > 0 && $remoteTargets === []) {
                $streamTerminal ??= ['event' => 'exception', 'message' => 'Gateway stream ended before declaring update targets.'];
                break;
            }

            usleep(50_000);
        }

        $this->targets = array_merge(
            [['target' => 'local', 'node' => null, 'role' => null]],
            $remoteTargets,
        );

        $progress = $this->openProgress();
        $localWorker = $this->startLocalTargetWorker($updater);

        if ($localWorker === null) {
            return $this->executeControlHumanPathSequential($updater, $gatewayStream);
        }

        ['pipe' => $localPipe, 'pid' => $localPid] = $localWorker;
        stream_set_blocking($localPipe, false);

        $localBuffer = '';
        $updatesByIndex = [];
        $localFailures = [];
        $streamUpdates = [];
        $streamError = null;
        $streamData = [];
        $streamResult = null;
        $localDone = false;
        $streamDone = $streamTerminal !== null;

        foreach ($queuedStreamEvents as $event) {
            $this->applyGatewayStreamEvent($event, $progress, $streamUpdates, $streamError, $streamData, $streamResult);
        }

        while (! $localDone || ! $streamDone) {
            if (! $localDone) {
                $this->drainTargetEvents($localPipe, $localBuffer, $progress, $updatesByIndex, $localFailures);

                if (pcntl_waitpid($localPid, $status, WNOHANG) > 0) {
                    $this->drainTargetEvents($localPipe, $localBuffer, $progress, $updatesByIndex, $localFailures);
                    fclose($localPipe);
                    $localDone = true;
                }
            }

            if (! $streamDone) {
                $this->drainGatewayStreamEvents($streamPipe, $streamBuffer, function (array $event) use ($progress, &$streamUpdates, &$streamError, &$streamData, &$streamResult): void {
                    $this->applyGatewayStreamEvent($event, $progress, $streamUpdates, $streamError, $streamData, $streamResult);
                });

                if ($streamResult !== null || $streamError !== null || pcntl_waitpid($streamPid, $status, WNOHANG) > 0) {
                    $this->drainGatewayStreamEvents($streamPipe, $streamBuffer, function (array $event) use ($progress, &$streamUpdates, &$streamError, &$streamData, &$streamResult): void {
                        $this->applyGatewayStreamEvent($event, $progress, $streamUpdates, $streamError, $streamData, $streamResult);
                    });
                    fclose($streamPipe);
                    $streamDone = true;
                }
            }

            $progress->tick();
            usleep(50_000);
        }

        ksort($updatesByIndex);
        $updates = array_merge(array_values($updatesByIndex), $streamUpdates);

        if ($localFailures !== []) {
            $progress->finish(success: false, footer: 'Failed');
            $this->captureActivitySummary($updates, 'failed', 'local_checkout');

            foreach ($localFailures as $failure) {
                $this->writePostTreeFailure($failure['headline'], $failure['output']);
            }

            return self::FAILURE;
        }

        if ($streamResult instanceof GatewayApiException) {
            $progress->finish(success: false, footer: 'Failed');
            $this->captureActivitySummary($updates, 'failed', 'remote_update');

            return $this->failCommand(
                code: $streamResult->errorCode() ?? 'gateway_unavailable',
                message: $streamResult->getMessage() !== ''
                    ? $streamResult->getMessage()
                    : 'Gateway connection is required to update the fleet.',
                meta: $streamResult->errorMeta(),
                data: $streamResult->errorData(),
            );
        }

        if ($streamResult !== self::SUCCESS || $streamError !== null) {
            $progress->finish(success: false, footer: 'Failed');
            $this->captureActivitySummary($updates, 'failed', 'remote_update');

            return $this->failCommand(
                code: is_string($streamData['code'] ?? null) ? $streamData['code'] : 'remote_update_failed',
                message: $streamError ?? 'One or more Orbit installations failed to update.',
                data: ['updates' => $updates],
                meta: [
                    'summary' => $this->summary($updates),
                ],
            );
        }

        $progress->finish(success: true, footer: 'Successfully updated '.$this->nodeCountLabel(count($updates)));
        $this->captureActivitySummary($updates, 'completed');

        return $this->respondSuccess($updates);
    }

    private function executeControlHumanPathSequential(OrbitUpdater $updater, UpdateAllGatewayStream $gatewayStream): int
    {
        $this->targets = [['target' => 'local', 'node' => null, 'role' => null]];
        $localUpdate = ['target' => 'local', 'node' => null, 'role' => null, 'status' => 'pending'];

        $progress = $this->openProgress();
        $localResult = $this->updateLocalWithProgress($updater, $progress);
        $localUpdate['status'] = $localResult->successful() ? 'completed' : 'failed';

        if (! $localResult->successful()) {
            $progress->finish(success: false, footer: 'Failed');
            $this->captureActivitySummary([$localUpdate], 'failed', 'local_checkout');
            $this->writePostTreeFailure('Failed to update local Orbit checkout.', $localResult->errorOutput() ?: $localResult->output());

            return self::FAILURE;
        }

        $streamUpdates = [];
        $streamError = null;
        $streamData = [];

        $streamResult = $gatewayStream->run(function (string $event, array $payload) use ($progress, &$streamUpdates, &$streamError, &$streamData): void {
            if ($event === 'tree') {
                $this->mergeStreamTargets($payload, $progress);

                return;
            }

            if ($event === 'step') {
                $this->applyStreamStep($payload, $progress);

                return;
            }

            if (in_array($event, ['complete', 'error'], true)) {
                $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
                $streamUpdates = is_array($data['updates'] ?? null) ? $data['updates'] : [];
                $streamData = $data;

                if ($event === 'error') {
                    $streamError = is_string($payload['message'] ?? null)
                        ? $payload['message']
                        : 'One or more Orbit installations failed to update.';
                }
            }
        });

        $updates = array_merge([$localUpdate], $streamUpdates);

        if ($streamResult instanceof GatewayApiException) {
            $progress->finish(success: false, footer: 'Failed');
            $this->captureActivitySummary($updates, 'failed', 'remote_update');

            return $this->failCommand(
                code: $streamResult->errorCode() ?? 'gateway_unavailable',
                message: $streamResult->getMessage() !== ''
                    ? $streamResult->getMessage()
                    : 'Gateway connection is required to update the fleet.',
                meta: $streamResult->errorMeta(),
                data: $streamResult->errorData(),
            );
        }

        if ($streamResult !== self::SUCCESS || $streamError !== null) {
            $progress->finish(success: false, footer: 'Failed');
            $this->captureActivitySummary($updates, 'failed', 'remote_update');

            return $this->failCommand(
                code: is_string($streamData['code'] ?? null) ? $streamData['code'] : 'remote_update_failed',
                message: $streamError ?? 'One or more Orbit installations failed to update.',
                data: ['updates' => $updates],
                meta: [
                    'summary' => $this->summary($updates),
                ],
            );
        }

        $progress->finish(success: true, footer: 'Successfully updated '.$this->nodeCountLabel(count($updates)));
        $this->captureActivitySummary($updates, 'completed');

        return $this->respondSuccess($updates);
    }

    private function executeGatewayPath(OrbitUpdater $updater): int
    {
        $nodes = Node::query()
            ->where('status', 'active')
            ->where('role', 'app')
            ->orderBy('name')
            ->get();

        $this->targets = $this->buildTargets($nodes);

        if ($this->wantsJson()) {
            return $this->handleJson($updater, $nodes);
        }

        return $this->handleHuman($updater, $nodes);
    }

    /**
     * @param  Collection<int, Node>  $nodes
     */
    private function handleJson(OrbitUpdater $updater, $nodes): int
    {
        $updates = [];
        $localResult = $updater->updateLocal();

        $updates[] = [
            'target' => 'local',
            'node' => null,
            'role' => null,
            'status' => $localResult->successful() ? 'completed' : 'failed',
        ];

        if (! $localResult->successful()) {
            $this->captureActivitySummary($updates, 'failed', 'local_checkout');

            return $this->failCommand(
                code: 'local_update_failed',
                message: 'Failed to update local Orbit checkout.',
                data: [
                    'output' => trim($localResult->errorOutput() ?: $localResult->output()),
                ],
                meta: ['failed_step' => 'local_checkout'],
            );
        }

        foreach ($nodes as $node) {
            $result = $updater->updateRemote($node);
            $updates[] = [
                'target' => $node->name,
                'node' => $node->name,
                'role' => $node->role,
                'status' => $result->successful() ? 'completed' : 'failed',
                ...($result->successful() ? [] : ['output' => trim($result->errorOutput() ?: $result->output())]),
            ];
        }

        $completed = count(array_filter($updates, fn (array $u): bool => $u['status'] === 'completed'));
        $failed = count(array_filter($updates, fn (array $u): bool => $u['status'] === 'failed'));

        if ($failed > 0) {
            $this->captureActivitySummary($updates, 'failed', 'remote_update');

            return $this->failCommand(
                code: 'remote_update_failed',
                message: 'One or more Orbit installations failed to update.',
                data: ['updates' => $updates],
                meta: [
                    'summary' => [
                        'total' => count($updates),
                        'completed' => $completed,
                        'failed' => $failed,
                    ],
                ],
            );
        }

        $this->captureActivitySummary($updates, 'completed');

        return $this->respondSuccess($updates);
    }

    /**
     * @param  Collection<int, Node>  $nodes
     */
    private function handleHuman(OrbitUpdater $updater, $nodes): int
    {
        $progress = $this->openProgress();
        [$updates, $failures] = $this->runTargetsWithProgress($updater, $nodes, $progress);
        $failed = $failures !== [];

        if ($failed) {
            $progress->finish(success: false, footer: 'Failed');
            $this->captureActivitySummary($updates, 'failed', 'remote_update');

            foreach ($failures as $failure) {
                $this->writePostTreeFailure($failure['headline'], $failure['output']);
            }

            return self::FAILURE;
        }

        $progress->finish(success: true, footer: 'Successfully updated '.$this->nodeCountLabel(count($updates)));
        $this->captureActivitySummary($updates, 'completed');

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, Node>  $nodes
     * @return array{
     *     0: list<array<string, mixed>>,
     *     1: list<array{headline: string, output: string}>,
     * }
     */
    private function runTargetsWithProgress(OrbitUpdater $updater, $nodes, UpdateAllProgress $progress): array
    {
        if (! function_exists('pcntl_fork') || ! function_exists('stream_socket_pair')) {
            return $this->runTargetsSequentiallyWithProgress($updater, $nodes, $progress);
        }

        $targets = [
            [
                'key' => 'local',
                'node' => null,
                'role' => null,
                'run' => function (mixed $pipe, int $index) use ($updater): void {
                    $this->runLocalTargetWorker($updater, $pipe, $index);
                },
            ],
        ];

        foreach ($nodes as $node) {
            $targets[] = [
                'key' => $node->name,
                'node' => $node->name,
                'role' => $node->role,
                'run' => function (mixed $pipe, int $index) use ($updater, $node): void {
                    $this->runRemoteTargetWorker($updater, $node, $pipe, $index);
                },
            ];
        }

        $pipes = [];
        $pids = [];
        $buffers = [];
        $completed = [];

        foreach ($targets as $index => $target) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

            if ($pair === false) {
                return $this->runTargetsSequentiallyWithProgress($updater, $nodes, $progress);
            }

            $pid = pcntl_fork();

            if ($pid === -1) {
                fclose($pair[0]);
                fclose($pair[1]);

                return $this->runTargetsSequentiallyWithProgress($updater, $nodes, $progress);
            }

            if ($pid === 0) {
                fclose($pair[0]);
                ($target['run'])($pair[1], $index);
                fclose($pair[1]);
                posix_kill(getmypid(), SIGKILL);
            }

            fclose($pair[1]);
            stream_set_blocking($pair[0], false);
            $pipes[$index] = $pair[0];
            $pids[$index] = $pid;
            $buffers[$index] = '';
            $completed[$index] = false;
        }

        $updatesByIndex = [];
        $failures = [];

        while (in_array(false, $completed, true)) {
            foreach ($pipes as $index => $pipe) {
                if ($completed[$index]) {
                    continue;
                }

                $this->drainTargetEvents($pipe, $buffers[$index], $progress, $updatesByIndex, $failures);

                $wait = pcntl_waitpid($pids[$index], $status, WNOHANG);

                if ($wait <= 0) {
                    continue;
                }

                $this->drainTargetEvents($pipe, $buffers[$index], $progress, $updatesByIndex, $failures);
                fclose($pipe);
                $completed[$index] = true;

                if (! isset($updatesByIndex[$index])) {
                    $target = $targets[$index];
                    $key = (string) $target['key'];
                    $progress->fail($key, 'failed');
                    $updatesByIndex[$index] = [
                        'target' => $key,
                        'node' => $target['node'],
                        'role' => $target['role'],
                        'status' => 'failed',
                    ];
                    $failures[] = [
                        'headline' => $key === 'local' ? 'Failed to update local Orbit checkout.' : "Failed to update node {$key}.",
                        'output' => 'Worker exited without reporting a result.',
                    ];
                }
            }

            $progress->tick();
            usleep(50_000);
        }

        ksort($updatesByIndex);

        return [array_values($updatesByIndex), $failures];
    }

    /**
     * @param  Collection<int, Node>  $nodes
     * @return array{
     *     0: list<array<string, mixed>>,
     *     1: list<array{headline: string, output: string}>,
     * }
     */
    private function runTargetsSequentiallyWithProgress(OrbitUpdater $updater, $nodes, UpdateAllProgress $progress): array
    {
        $updates = [];
        $failures = [];

        $localResult = $this->updateLocalWithProgress($updater, $progress);
        $updates[] = [
            'target' => 'local',
            'node' => null,
            'role' => null,
            'status' => $localResult->successful() ? 'completed' : 'failed',
        ];

        if (! $localResult->successful()) {
            return [$updates, [[
                'headline' => 'Failed to update local Orbit checkout.',
                'output' => $localResult->errorOutput() ?: $localResult->output(),
            ]]];
        }

        foreach ($nodes as $node) {
            $result = $this->updateRemoteWithProgress($updater, $node, $progress);
            $updates[] = [
                'target' => $node->name,
                'node' => $node->name,
                'role' => $node->role,
                'status' => $result->successful() ? 'completed' : 'failed',
            ];

            if (! $result->successful()) {
                $failures[] = [
                    'headline' => "Failed to update node {$node->name}.",
                    'output' => $result->errorOutput() ?: $result->output(),
                ];
            }
        }

        return [$updates, $failures];
    }

    private function runLocalTargetWorker(OrbitUpdater $updater, mixed $pipe, int $index): void
    {
        foreach (['pulling_source', 'installing_dependencies', 'running_migrations'] as $stage) {
            $this->writeTargetEvent($pipe, [
                'type' => 'stage',
                'index' => $index,
                'key' => 'local',
                'stage' => $stage,
            ]);

            $result = match ($stage) {
                'pulling_source' => $updater->pullSource(),
                'installing_dependencies' => $updater->installDependencies(),
                'running_migrations' => $updater->runMigrations(),
            };

            if (! $result->successful()) {
                $this->writeTargetEvent($pipe, [
                    'type' => 'fail',
                    'index' => $index,
                    'key' => 'local',
                    'node' => null,
                    'role' => null,
                    'output' => $this->failureMessage($result->errorOutput() ?: $result->output()),
                ]);

                return;
            }
        }

        $this->writeTargetEvent($pipe, [
            'type' => 'done',
            'index' => $index,
            'key' => 'local',
            'node' => null,
            'role' => null,
        ]);
    }

    /**
     * @return array{pipe: resource, pid: int}|null
     */
    private function startLocalTargetWorker(OrbitUpdater $updater): ?array
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
            $this->runLocalTargetWorker($updater, $pair[1], 0);
            fclose($pair[1]);
            posix_kill(getmypid(), SIGKILL);
        }

        fclose($pair[1]);

        return ['pipe' => $pair[0], 'pid' => $pid];
    }

    /**
     * @return array{pipe: resource, pid: int}|null
     */
    private function startGatewayStreamWorker(UpdateAllGatewayStream $gatewayStream): ?array
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
            $result = $gatewayStream->run(function (string $event, array $payload) use ($pair): void {
                $this->writeGatewayStreamEvent($pair[1], [
                    'event' => $event,
                    'payload' => $payload,
                ]);
            });

            if ($result instanceof GatewayApiException) {
                $this->writeGatewayStreamEvent($pair[1], [
                    'event' => 'exception',
                    'message' => $result->getMessage(),
                    'code' => $result->errorCode(),
                    'meta' => $result->errorMeta(),
                    'data' => $result->errorData(),
                ]);
            } else {
                $this->writeGatewayStreamEvent($pair[1], [
                    'event' => 'result',
                    'result' => $result,
                ]);
            }

            fclose($pair[1]);
            posix_kill(getmypid(), SIGKILL);
        }

        fclose($pair[1]);

        return ['pipe' => $pair[0], 'pid' => $pid];
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function writeGatewayStreamEvent(mixed $pipe, array $event): void
    {
        fwrite($pipe, json_encode($event, JSON_THROW_ON_ERROR)."\n");
    }

    /**
     * @param  callable(array<string, mixed>): void  $onEvent
     */
    private function drainGatewayStreamEvents(mixed $pipe, string &$buffer, callable $onEvent): void
    {
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

            $onEvent($event);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{target: string, node: string|null, role: string|null}>
     */
    private function remoteTargetsFromStreamPayload(array $payload): array
    {
        $steps = is_array($payload['steps'] ?? null) ? $payload['steps'] : [];
        $targets = [];

        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }

            $key = $step['key'] ?? null;

            if (! is_string($key) || $key === '') {
                continue;
            }

            $targets[] = ['target' => $key, 'node' => $key, 'role' => null];
        }

        return $targets;
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  list<array<string, mixed>>  $streamUpdates
     * @param  array<string, mixed>  $streamData
     */
    private function applyGatewayStreamEvent(
        array $event,
        UpdateAllProgress $progress,
        array &$streamUpdates,
        ?string &$streamError,
        array &$streamData,
        GatewayApiException|int|null &$streamResult,
    ): void {
        $eventName = is_string($event['event'] ?? null) ? $event['event'] : null;

        if ($eventName === 'tree') {
            return;
        }

        if ($eventName === 'step') {
            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
            $this->applyStreamStep($payload, $progress);

            return;
        }

        if (in_array($eventName, ['complete', 'error'], true)) {
            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
            /** @var array<string, mixed> $data */
            $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
            $updates = is_array($data['updates'] ?? null) ? $data['updates'] : [];
            $streamUpdates = [];

            foreach ($updates as $update) {
                if (is_array($update)) {
                    /** @var array<string, mixed> $update */
                    $streamUpdates[] = $update;
                }
            }

            $streamData = $data;
            $streamResult = (int) ($payload['exit_code'] ?? ($eventName === 'complete' ? self::SUCCESS : self::FAILURE));

            if ($eventName === 'error') {
                $streamError = is_string($payload['message'] ?? null)
                    ? $payload['message']
                    : 'One or more Orbit installations failed to update.';
            }

            return;
        }

        if ($eventName === 'exception') {
            $streamResult = new GatewayApiException(
                message: is_string($event['message'] ?? null) ? $event['message'] : 'Gateway connection is required to update the fleet.',
                errorCode: is_string($event['code'] ?? null) ? $event['code'] : null,
                errorMeta: is_array($event['meta'] ?? null) ? $event['meta'] : [],
                errorData: is_array($event['data'] ?? null) ? $event['data'] : [],
            );

            return;
        }

        if ($eventName === 'result') {
            $streamResult = is_int($event['result'] ?? null) ? $event['result'] : self::FAILURE;
        }
    }

    private function runRemoteTargetWorker(OrbitUpdater $updater, Node $node, mixed $pipe, int $index): void
    {
        foreach (['pulling_source', 'installing_dependencies', 'running_migrations'] as $stage) {
            $this->writeTargetEvent($pipe, [
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
                $this->writeTargetEvent($pipe, [
                    'type' => 'fail',
                    'index' => $index,
                    'key' => $node->name,
                    'node' => $node->name,
                    'role' => $node->role,
                    'output' => $this->failureMessage($result->errorOutput() ?: $result->output()),
                ]);

                return;
            }
        }

        $this->writeTargetEvent($pipe, [
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
    private function writeTargetEvent(mixed $pipe, array $event): void
    {
        fwrite($pipe, json_encode($event, JSON_THROW_ON_ERROR)."\n");
    }

    /**
     * @param  array<int, array<string, mixed>>  $updatesByIndex
     * @param  list<array{headline: string, output: string}>  $failures
     */
    private function drainTargetEvents(
        mixed $pipe,
        string &$buffer,
        UpdateAllProgress $progress,
        array &$updatesByIndex,
        array &$failures,
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

            $this->applyTargetEvent($event, $progress, $updatesByIndex, $failures);
        }
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<int, array<string, mixed>>  $updatesByIndex
     * @param  list<array{headline: string, output: string}>  $failures
     */
    private function applyTargetEvent(
        array $event,
        UpdateAllProgress $progress,
        array &$updatesByIndex,
        array &$failures,
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
                $progress->stage($key, $stage);
            }

            return;
        }

        $node = is_string($event['node'] ?? null) ? $event['node'] : null;
        $role = is_string($event['role'] ?? null) ? $event['role'] : null;

        if ($type === 'done') {
            $progress->done($key);
            $updatesByIndex[$index] = [
                'target' => $key,
                'node' => $node,
                'role' => $role,
                'status' => 'completed',
            ];

            return;
        }

        if ($type === 'fail') {
            $output = is_string($event['output'] ?? null) ? $event['output'] : 'failed';
            $progress->fail($key, $output);
            $updatesByIndex[$index] = [
                'target' => $key,
                'node' => $node,
                'role' => $role,
                'status' => 'failed',
            ];
            $failures[] = [
                'headline' => $key === 'local' ? 'Failed to update local Orbit checkout.' : "Failed to update node {$key}.",
                'output' => $output,
            ];
        }
    }

    private function updateLocalWithProgress(OrbitUpdater $updater, UpdateAllProgress $progress): ProcessResult
    {
        $progress->start('local');
        $progress->stage('local', 'pulling_source');
        $result = $updater->pullSource();

        if (! $result->successful()) {
            $progress->fail('local', $this->failureMessage($result->errorOutput() ?: $result->output()));

            return $result;
        }

        $progress->stage('local', 'installing_dependencies');
        $result = $updater->installDependencies();

        if (! $result->successful()) {
            $progress->fail('local', $this->failureMessage($result->errorOutput() ?: $result->output()));

            return $result;
        }

        $progress->stage('local', 'running_migrations');
        $result = $updater->runMigrations();

        if (! $result->successful()) {
            $progress->fail('local', $this->failureMessage($result->errorOutput() ?: $result->output()));

            return $result;
        }

        $progress->done('local');

        return $result;
    }

    private function updateRemoteWithProgress(OrbitUpdater $updater, Node $node, UpdateAllProgress $progress): RemoteShellResult
    {
        $progress->start($node->name);
        $progress->stage($node->name, 'pulling_source');
        $result = $updater->pullRemoteSource($node);

        if (! $result->successful()) {
            $progress->fail($node->name, $this->failureMessage($result->errorOutput() ?: $result->output()));

            return $result;
        }

        $progress->stage($node->name, 'installing_dependencies');
        $result = $updater->installRemoteDependencies($node);

        if (! $result->successful()) {
            $progress->fail($node->name, $this->failureMessage($result->errorOutput() ?: $result->output()));

            return $result;
        }

        $progress->stage($node->name, 'running_migrations');
        $result = $updater->runRemoteMigrations($node);

        if (! $result->successful()) {
            $progress->fail($node->name, $this->failureMessage($result->errorOutput() ?: $result->output()));

            return $result;
        }

        $progress->done($node->name);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function mergeStreamTargets(array $payload, UpdateAllProgress $progress): void
    {
        $steps = is_array($payload['steps'] ?? null) ? $payload['steps'] : [];
        $remoteTargets = [];

        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }

            $key = $step['key'] ?? null;

            if (! is_string($key) || $key === '') {
                continue;
            }

            $remoteTargets[] = ['target' => $key, 'node' => $key, 'role' => null];
        }

        $this->targets = array_merge(
            [['target' => 'local', 'node' => null, 'role' => null]],
            $remoteTargets,
        );

        $progress->extendWith($remoteTargets);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyStreamStep(array $payload, UpdateAllProgress $progress): void
    {
        $key = is_string($payload['key'] ?? null) ? $payload['key'] : null;
        $status = is_string($payload['status'] ?? null) ? $payload['status'] : null;

        if ($key === null || $status === null) {
            return;
        }

        match ($status) {
            'start' => $progress->start($key),
            'pulling_source', 'installing_dependencies', 'running_migrations' => $progress->stage($key, $status),
            'done' => $progress->done($key),
            'fail' => $progress->fail($key, is_string($payload['message'] ?? null) ? $payload['message'] : 'failed'),
            'skip' => $progress->done($key),
            default => null,
        };
    }

    private function failureMessage(string $output): string
    {
        $message = trim($output);

        return $message !== '' ? $message : 'failed';
    }

    private function writePostTreeFailure(string $headline, string $captured): void
    {
        $this->line('');
        $this->error($headline);

        $captured = trim($captured);

        if ($captured !== '') {
            $this->line($captured);
        }
    }

    private function openProgress(): UpdateAllProgress
    {
        return new UpdateAllProgress(
            output: $this->output,
            initialTargets: $this->targets,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $updates
     */
    private function captureActivitySummary(array $updates, string $status, ?string $failedStep = null): void
    {
        $this->activityStatus = $status;
        $this->activityFailedStep = $failedStep;
        $this->activitySummary = [
            'total' => count($updates),
            'completed' => count(array_filter($updates, fn (array $update): bool => $update['status'] === 'completed')),
            'failed' => count(array_filter($updates, fn (array $update): bool => $update['status'] === 'failed')),
        ];
    }

    private function finishActivityLog(): void
    {
        try {
            $this->finalizeActivityLog();
        } catch (Throwable) {
            // Activity logging must not change the documented update:all result.
        }
    }

    private function hasConfiguredGateway(): bool
    {
        return LocalGatewaySettings::query()
            ->whereNotNull('gateway_url')
            ->where('gateway_url', '!=', '')
            ->exists();
    }

    /**
     * @param  Collection<int, Node>  $nodes
     * @return list<array{target: string, node: string|null, role: string|null}>
     */
    private function buildTargets($nodes): array
    {
        $targets = [['target' => 'local', 'node' => null, 'role' => null]];

        foreach ($nodes as $node) {
            $targets[] = ['target' => $node->name, 'node' => $node->name, 'role' => $node->role];
        }

        return $targets;
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

    private function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }

    /**
     * @param  list<array<string, mixed>>  $updates
     */
    private function respondSuccess(array $updates): int
    {
        $completed = count(array_filter($updates, fn (array $u): bool => $u['status'] === 'completed'));
        $failed = count(array_filter($updates, fn (array $u): bool => $u['status'] === 'failed'));

        if ($this->wantsJson()) {
            $this->line(json_encode([
                'success' => [
                    'data' => [
                        'updates' => $updates,
                    ],
                    'meta' => [
                        'summary' => [
                            'total' => count($updates),
                            'completed' => $completed,
                            'failed' => $failed,
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $data
     */
    private function failCommand(string $code, string $message, array $meta = [], array $data = []): int
    {
        if ($this->wantsJson()) {
            $error = [
                'code' => $code,
                'message' => $message,
                'meta' => $meta,
            ];

            if ($data !== []) {
                $error['data'] = $data;
            }

            $this->line(json_encode([
                'error' => $error,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }

        $this->error($message);

        $output = $data['output'] ?? null;

        if (is_string($output) && trim($output) !== '') {
            $this->line(trim($output));
        }

        return self::FAILURE;
    }

    private function nodeCountLabel(int $count): string
    {
        return $count.' '.($count === 1 ? 'node' : 'nodes');
    }
}
