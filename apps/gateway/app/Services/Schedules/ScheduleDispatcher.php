<?php

declare(strict_types=1);

namespace App\Services\Schedules;

use App\Data\RemoteShell\RemoteShellResult;
use App\Data\Schedules\ScheduleDispatchResult;
use App\Enums\Schedules\ScheduleScope;
use App\Models\Node;
use App\Models\Schedule;
use App\Models\ScheduleRun;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\RemoteShell\Exceptions\RemoteShellProtocolException;
use App\Services\RemoteShell\RemoteShellSuccessData;
use App\Services\RemoteShell\RunsInternalCommands;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Process;
use Orbit\Core\Enums\InternalCommand;
use Orbit\Sdk\Laravel\GatewayApiException;
use Throwable;

final readonly class ScheduleDispatcher
{
    public function __construct(
        private RunsInternalCommands $localExecutor,
        private NodeRoleAssignments $nodeRoleAssignments,
        private ScheduleInstanceResolver $instances,
    ) {}

    public function run(Schedule $schedule): ScheduleDispatchResult
    {
        return $this->runMany([$schedule])[0];
    }

    /**
     * @param  list<Schedule>  $schedules
     * @return list<ScheduleDispatchResult>
     */
    public function runMany(array $schedules): array
    {
        if ($schedules === []) {
            return [];
        }

        $resultsByIndex = [];
        foreach (array_values($schedules) as $index => $schedule) {
            $schedule->loadMissing(['app', 'instance', 'node']);

            $targetNode = $this->targetNode($schedule);

            if (! $targetNode instanceof Node) {
                $message = "Schedule '{$schedule->name}' does not resolve to a target node.";

                if (($gatewayNode = $this->gatewayNode()) instanceof Node) {
                    $resultsByIndex[$index] = $this->recordDispatchFailure($schedule, $gatewayNode, $message);

                    continue;
                }

                throw new GatewayApiException($message, 'validation_failed', [
                    'field' => 'target',
                    'schedule' => $schedule->name,
                ]);
            }

            if ($this->isGatewayNode($targetNode)) {
                $resultsByIndex[$index] = $this->runLocallyAndRecord($schedule, $targetNode);

                continue;
            }

            $resultsByIndex[$index] = $this->runRemotelyAndRecord($schedule, $targetNode);
        }

        ksort($resultsByIndex);

        return array_values($resultsByIndex);
    }

    private function runLocallyAndRecord(Schedule $schedule, Node $targetNode): ScheduleDispatchResult
    {
        $startedAt = CarbonImmutable::now();
        $startedHrtime = hrtime(true);

        try {
            $result = $this->runLocally($schedule);
        } catch (Throwable $throwable) {
            $finishedAt = CarbonImmutable::now();
            $durationMs = (int) ((hrtime(true) - $startedHrtime) / 1_000_000);

            return new ScheduleDispatchResult(
                run: $this->recordRun(
                    schedule: $schedule,
                    targetNode: $targetNode,
                    status: 'failed',
                    exitCode: 1,
                    stdout: '',
                    stderr: $throwable->getMessage(),
                    startedAt: $startedAt,
                    finishedAt: $finishedAt,
                ),
                targetNode: $targetNode,
                durationMs: $durationMs,
            );
        }

        $finishedAt = CarbonImmutable::now();

        return new ScheduleDispatchResult(
            run: $this->recordRun(
                schedule: $schedule,
                targetNode: $targetNode,
                status: $result->successful() ? 'completed' : 'failed',
                exitCode: $result->exitCode,
                stdout: $result->stdout,
                stderr: $result->stderr,
                startedAt: $startedAt,
                finishedAt: $finishedAt,
            ),
            targetNode: $targetNode,
            durationMs: $result->durationMs,
        );
    }

    private function runRemotelyAndRecord(Schedule $schedule, Node $targetNode): ScheduleDispatchResult
    {
        $startedAt = CarbonImmutable::now();
        $startedHrtime = hrtime(true);

        try {
            $result = $this->runRemotely($schedule, $targetNode);
        } catch (Throwable $throwable) {
            $finishedAt = CarbonImmutable::now();
            $durationMs = (int) ((hrtime(true) - $startedHrtime) / 1_000_000);

            return new ScheduleDispatchResult(
                run: $this->recordRun(
                    schedule: $schedule,
                    targetNode: $targetNode,
                    status: 'failed',
                    exitCode: 1,
                    stdout: '',
                    stderr: $throwable->getMessage(),
                    startedAt: $startedAt,
                    finishedAt: $finishedAt,
                ),
                targetNode: $targetNode,
                durationMs: $durationMs,
            );
        }

        $finishedAt = CarbonImmutable::now();

        return new ScheduleDispatchResult(
            run: $this->recordRun(
                schedule: $schedule,
                targetNode: $targetNode,
                status: $result->successful() ? 'completed' : 'failed',
                exitCode: $result->exitCode,
                stdout: $result->stdout,
                stderr: $result->stderr,
                startedAt: $startedAt,
                finishedAt: $finishedAt,
            ),
            targetNode: $targetNode,
            durationMs: $result->durationMs,
        );
    }

    private function runRemotely(Schedule $schedule, Node $targetNode): RemoteShellResult
    {
        $result = $this->localExecutor->runInternal(
            node: $targetNode,
            commandName: InternalCommand::ScheduleRun->value,
            transportOptions: [
                'input' => json_encode($this->executionPayload($schedule), JSON_THROW_ON_ERROR),
                'metadata' => [
                    'ORBIT_OPERATION_ID' => 'schedule.dispatch',
                ],
                'strict' => false,
                'timeout' => $this->executionTimeout($schedule) + 15,
            ],
        );

        if (! $result->successful()) {
            return $result;
        }

        return $this->fromSuccessEnvelope($result);
    }

    private function fromSuccessEnvelope(RemoteShellResult $result): RemoteShellResult
    {
        try {
            $data = RemoteShellSuccessData::fromJsonEnvelopeOrFail($result);
        } catch (RemoteShellProtocolException) {
            return new RemoteShellResult(
                exitCode: 1,
                stdout: $result->stdout,
                stderr: 'Schedule run response is invalid.',
                durationMs: $result->durationMs,
            );
        }

        if (
            ! is_int($data['exit_code'] ?? null)
            || ! is_string($data['stdout'] ?? null)
            || ! is_string($data['stderr'] ?? null)
            || ! is_int($data['duration_ms'] ?? null)
        ) {
            return new RemoteShellResult(
                exitCode: 1,
                stdout: $result->stdout,
                stderr: 'Schedule run response is invalid.',
                durationMs: $result->durationMs,
            );
        }

        return new RemoteShellResult(
            exitCode: $data['exit_code'],
            stdout: $data['stdout'],
            stderr: $data['stderr'],
            durationMs: $data['duration_ms'],
        );
    }

    /**
     * @return array{execution_type: string, execution_value: string, cwd: string|null, timeout: int}
     */
    private function executionPayload(Schedule $schedule): array
    {
        $options = $this->executionOptions($schedule);

        return [
            'execution_type' => $schedule->execution_type,
            'execution_value' => $schedule->execution_value,
            'cwd' => $options['cwd'] ?? null,
            'timeout' => $options['timeout'],
        ];
    }

    private function targetNode(Schedule $schedule): ?Node
    {
        return match ($schedule->ownerScope()) {
            ScheduleScope::Instance => $this->instances->targetNode($schedule),
            ScheduleScope::Node => $schedule->node,
            ScheduleScope::Orbit => $this->gatewayNode(),
        };
    }

    private function gatewayNode(): ?Node
    {
        return $this->nodeRoleAssignments
            ->activeGatewayNodeQuery()
            ->first();
    }

    private function isGatewayNode(Node $node): bool
    {
        return $this->nodeRoleAssignments->nodeIsGateway($node);
    }

    private function runLocally(Schedule $schedule): RemoteShellResult
    {
        $options = $this->executionOptions($schedule);
        $pendingProcess = Process::timeout($options['timeout']);

        if (isset($options['cwd']) && $options['cwd'] !== '') {
            $pendingProcess = $pendingProcess->path($options['cwd']);
        }

        $startedAt = hrtime(true);
        $processResult = $pendingProcess->run($this->executionScript($schedule));
        $durationMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

        return new RemoteShellResult(
            exitCode: $processResult->exitCode() ?? 1,
            stdout: $processResult->output(),
            stderr: $processResult->errorOutput(),
            durationMs: $durationMs,
        );
    }

    /**
     * @return array{cwd?: string, timeout: int}
     */
    private function executionOptions(Schedule $schedule): array
    {
        $options = ['timeout' => $this->executionTimeout($schedule)];

        $path = $schedule->isInstanceOwned() ? $this->instances->executionPath($schedule) : null;

        if (is_string($path) && $path !== '') {
            $options['cwd'] = $path;
        }

        return $options;
    }

    private function executionTimeout(Schedule $schedule): int
    {
        return max(1, min(86_400, $schedule->timeout_seconds));
    }

    private function executionScript(Schedule $schedule): string
    {
        if ($schedule->execution_type === 'script') {
            return escapeshellarg($schedule->execution_value);
        }

        return $schedule->execution_value;
    }

    private function recordDispatchFailure(
        Schedule $schedule,
        Node $targetNode,
        string $message,
    ): ScheduleDispatchResult {
        $startedAt = CarbonImmutable::now();

        return new ScheduleDispatchResult(
            run: $this->recordRun(
                schedule: $schedule,
                targetNode: $targetNode,
                status: 'failed',
                exitCode: 1,
                stdout: '',
                stderr: $message,
                startedAt: $startedAt,
                finishedAt: CarbonImmutable::now(),
            ),
            targetNode: $targetNode,
            durationMs: 0,
        );
    }

    private function recordRun(
        Schedule $schedule,
        Node $targetNode,
        string $status,
        int $exitCode,
        string $stdout,
        string $stderr,
        CarbonImmutable $startedAt,
        CarbonImmutable $finishedAt,
    ): ScheduleRun {
        return ScheduleRun::query()->create([
            'node_id' => $targetNode->id,
            'schedule_key' => $schedule->schedule_key,
            'target_name' => $schedule->liveTargetName(),
            'status' => $status,
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
        ]);
    }
}
