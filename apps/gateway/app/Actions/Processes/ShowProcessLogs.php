<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Models\Node;
use App\Models\Process;
use App\Services\Nodes\NodeHostPaths;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationStreamTokens;
use App\Services\Processes\ProcessOwnerContext;
use App\Services\Processes\ProcessRuntimeDriverRegistry;
use App\Services\Processes\ProcessRuntimeDrivers\DockerProcessRuntimeDriver;
use App\Services\Processes\ProcessRuntimeDrivers\DockerSwarmProcessRuntimeDriver;
use App\Services\Processes\ProcessRuntimeDrivers\LaunchdProcessRuntimeDriver;
use App\Services\Processes\ProcessRuntimeDrivers\ProcessRuntimeDriver;
use App\Services\Processes\ProcessRuntimeDrivers\SystemdProcessRuntimeDriver;
use App\Services\Processes\ProcessServiceMetadataPayload;
use App\Services\Processes\RemoteProcessLogs;
use Illuminate\Support\Str;
use Orbit\Sdk\Laravel\GatewayApiException;
use RuntimeException;

final readonly class ShowProcessLogs
{
    /**
     * @mago-expect lint:excessive-parameter-list
     */
    public function __construct(
        private ProcessRuntimeDriverRegistry $runtimeDrivers,
        private RemoteProcessLogs $remoteLogs,
        private ProcessServiceMetadataPayload $serviceMetadata,
        private OperationRunRecorder $operationRuns,
        private OperationStreamTokens $streamTokens,
        private NodeHostPaths $nodeHostPaths,
    ) {}

    /**
     * @return array{data: array{logs: array<string, mixed>}, meta: array{line_count: int}}
     */
    public function handle(ProcessOwnerContext $context, string $name, int $lines, bool $follow = false): array
    {
        $target = $this->target($context, $name, $lines, $follow);

        $result = $this->remoteLogs->read(
            node: $target['node'],
            backend: $target['backend'],
            runtimeUnit: $target['runtime_unit'],
            lines: $lines,
            stdoutPath: $target['stdout_path'],
            stderrPath: $target['stderr_path'],
        );

        if (! $result->successful()) {
            throw new GatewayApiException(
                'The runtime backend could not read the process log.',
                'process.log_read_failed',
                [
                    'process' => $name,
                    'runtime_unit' => $target['runtime_unit'],
                ],
            );
        }

        $parsedLines = $this->parseLines($this->output($result->stdout));

        return [
            'data' => [
                'logs' => [
                    'process' => $target['process']->name,
                    'node' => $target['node']->name,
                    'project' => $context->app?->name,
                    'instance' => $context->appInstance?->name,
                    'workspace' => $target['workspace'],
                    'runtime_unit' => $target['runtime_unit'],
                    'service' => $this->serviceMetadata->forProcess($target['process']),
                    'lines' => $parsedLines,
                ],
            ],
            'meta' => [
                'line_count' => count($parsedLines),
            ],
        ];
    }

    /**
     * @return array{node: Node, process: Process, workspace: string|null, runtime_unit: string, backend: string, script: string, lines: int, stdout_path: string|null, stderr_path: string|null}
     */
    public function streamTarget(
        ProcessOwnerContext $context,
        string $name,
        int $lines,
    ): array {
        return $this->target($context, $name, $lines, true);
    }

    /**
     * @return array{node: Node, process: Process, workspace: string|null, runtime_unit: string, backend: string, script: string, lines: int, stdout_path: string|null, stderr_path: string|null, operation_stream: array<string, mixed>}
     */
    public function operationStreamTarget(
        ProcessOwnerContext $context,
        string $name,
        int $lines,
        ?string $gatewayUrl = null,
    ): array {
        $target = $this->streamTarget($context, $name, $lines);

        $run = $this->operationRuns->queued(
            operationId: (string) Str::uuid(),
            lane: 'gateway',
            operationType: 'process.logs.follow',
            targetNodeId: $target['node']->id,
        );
        $this->operationRuns->running($run->id);

        $channel = "private-operations.{$run->id}";
        $publisher = $this->streamTokens->publisherToken($run, $channel);
        $target['operation_stream'] = [
            'operation_uuid' => $run->id,
            'channel' => $channel,
            'gateway_url' => $gatewayUrl,
            'ca_pem_path' => $this->nodeHostPaths->runtimeTrustPoolPath($target['node']),
            'publish_endpoint' => "/api/operations/{$run->id}/stream/publish",
            'stop_decision_endpoint' => "/api/operations/{$run->id}/stream/stop-decision",
            'publisher_token' => $publisher['token'],
            'publisher_expires_at' => $publisher['expires_at']->toIso8601String(),
        ];

        return $target;
    }

    /**
     * @param  array{node: Node, process: Process, workspace: string|null, runtime_unit: string, backend: string, script: string, lines: int, stdout_path: string|null, stderr_path: string|null, operation_stream?: array<string, mixed>}  $target
     * @param  callable(string): void  $onOutput
     */
    public function followTarget(array $target, callable $onOutput): void
    {
        $this->remoteLogs->follow(
            node: $target['node'],
            backend: $target['backend'],
            runtimeUnit: $target['runtime_unit'],
            lines: $target['lines'],
            onOutput: $onOutput,
            stdoutPath: $target['stdout_path'],
            stderrPath: $target['stderr_path'],
            operationStream: $target['operation_stream'] ?? null,
        );
    }

    /**
     * @return array{node: Node, process: Process, workspace: string|null, runtime_unit: string, backend: string, script: string, lines: int, stdout_path: string|null, stderr_path: string|null}
     */
    private function target(ProcessOwnerContext $context, string $name, int $lines, bool $follow): array
    {
        if ($lines < 1) {
            throw new GatewayApiException('The --lines value must be a positive integer.', 'validation_failed', [
                'field' => 'lines',
                'value' => $lines,
            ]);
        }

        $process = $context->lifecycleProcesses($name)->first();

        if (! $process instanceof Process) {
            throw new GatewayApiException(
                "Process '{$name}' not found for {$context->label()}.",
                'process.not_found',
                $context->errorMeta($name),
            );
        }

        $app = $context->runtimeApp();
        $workspace = $context->runtimeWorkspaceFor($process);
        $driver = $this->runtimeDrivers->forProcess($process);
        $runtimeUnit = $driver->runtimeUnitName($app, $process, $workspace);
        $stdoutPath = $driver instanceof LaunchdProcessRuntimeDriver
            ? $driver->stdoutLogPath($context->node, $runtimeUnit)
            : null;
        $stderrPath = $driver instanceof LaunchdProcessRuntimeDriver
            ? $driver->stderrLogPath($context->node, $runtimeUnit)
            : null;

        return [
            'node' => $context->node,
            'process' => $process,
            'workspace' => $workspace?->name,
            'runtime_unit' => $runtimeUnit,
            'backend' => $this->backend($driver),
            'script' => $driver->logScript($app, $process, $workspace, $runtimeUnit, $lines, $follow),
            'lines' => $lines,
            'stdout_path' => $stdoutPath,
            'stderr_path' => $stderrPath,
        ];
    }

    private function backend(ProcessRuntimeDriver $driver): string
    {
        return match (true) {
            $driver instanceof DockerProcessRuntimeDriver => 'docker',
            $driver instanceof DockerSwarmProcessRuntimeDriver => 'docker-swarm',
            $driver instanceof LaunchdProcessRuntimeDriver => 'launchd',
            $driver instanceof SystemdProcessRuntimeDriver => 'systemd',
            default => throw new RuntimeException('Unsupported process log runtime backend.'),
        };
    }

    private function output(string $stdout): string
    {
        /** @var mixed $payload */
        $payload = json_decode($stdout, associative: true);

        if (! is_array($payload)) {
            return '';
        }

        /** @var mixed $success */
        $success = $payload['success'] ?? null;

        if (! is_array($success)) {
            return '';
        }

        /** @var mixed $data */
        $data = $success['data'] ?? null;

        if (! is_array($data)) {
            return '';
        }

        /** @var mixed $output */
        $output = $data['output'] ?? null;

        return is_string($output) ? $output : '';
    }

    /**
     * @return list<array{timestamp: string|null, message: string}>
     */
    private function parseLines(string $output): array
    {
        return collect(preg_split('/\R/', trim($output)) ?: [])
            ->filter(fn (string $line): bool => $line !== '')
            ->map(function (string $line): array {
                if (preg_match('/^(?<timestamp>\d{4}-\d{2}-\d{2}T[^\s]+)\s+(?<message>.*)$/', $line, $matches) === 1) {
                    return [
                        'timestamp' => $matches['timestamp'],
                        'message' => $matches['message'],
                    ];
                }

                return [
                    'timestamp' => null,
                    'message' => $line,
                ];
            })
            ->values()
            ->all();
    }
}
