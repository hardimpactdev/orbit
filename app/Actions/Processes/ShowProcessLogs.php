<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Contracts\RemoteShell;
use App\Enums\Processes\ProcessRuntime;
use App\Http\Gateway\GatewayApiException;
use App\Models\App;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Processes\ProcessDockerContainerRenderer;
use App\Services\Processes\SupervisorProgramRenderer;

final readonly class ShowProcessLogs
{
    public function __construct(
        private RemoteShell $remoteShell,
        private SupervisorProgramRenderer $renderer,
        private ProcessDockerContainerRenderer $dockerRenderer,
    ) {}

    /**
     * @return array{data: array{logs: array<string, mixed>}, meta: array{line_count: int}}
     */
    public function handle(App $app, ?Workspace $workspace, string $name, int $lines, bool $follow = false): array
    {
        if ($lines < 1) {
            throw new GatewayApiException('The --lines value must be a positive integer.', 'validation_failed', [
                'field' => 'lines',
                'value' => $lines,
            ]);
        }

        $app->loadMissing('node');

        $process = Process::query()
            ->where('app_id', $app->id)
            ->where('name', $name)
            ->first();

        if (! $process instanceof Process) {
            throw new GatewayApiException("Process '{$name}' not found for app '{$app->name}'.", 'process.not_found', [
                'app' => $app->name,
                'name' => $name,
            ]);
        }

        if ($app->node === null) {
            throw new GatewayApiException("App '{$app->name}' has no node.", 'process.log_read_failed', [
                'app' => $app->name,
            ]);
        }

        $runtimeUnit = $this->resolveRuntimeUnit($app, $process, $workspace);
        $script = $this->buildLogScript($app, $process, $workspace, $runtimeUnit, $lines, $follow);

        $result = $this->remoteShell->run($app->node, $script);

        if (! $result->successful()) {
            throw new GatewayApiException('The runtime backend could not read the process log.', 'process.log_read_failed', [
                'process' => $name,
                'runtime_unit' => $runtimeUnit,
            ]);
        }

        $parsedLines = $this->parseLines($result->output());

        return [
            'data' => [
                'logs' => [
                    'process' => $process->name,
                    'app' => $app->name,
                    'workspace' => $workspace?->name,
                    'runtime_unit' => $runtimeUnit,
                    'lines' => $parsedLines,
                ],
            ],
            'meta' => [
                'line_count' => count($parsedLines),
            ],
        ];
    }

    private function resolveRuntimeUnit(App $app, Process $process, ?Workspace $workspace): string
    {
        if ($process->runtime === ProcessRuntime::Docker) {
            return $this->dockerRenderer->containerName($app, $process, $workspace);
        }

        return $this->renderer->programName($app, $process, $workspace);
    }

    private function buildLogScript(App $app, Process $process, ?Workspace $workspace, string $runtimeUnit, int $lines, bool $follow): string
    {
        if ($process->runtime === ProcessRuntime::Docker) {
            return collect([
                'docker logs',
                "--tail {$lines}",
                $follow ? '--follow' : null,
                escapeshellarg($runtimeUnit),
            ])->filter()->implode(' ');
        }

        $definition = $this->renderer->definition($app, $process, $workspace);

        return collect([
            'sudo tail',
            "-n {$lines}",
            $follow ? '-F' : null,
            escapeshellarg($definition->stdoutLogFile),
        ])->filter()->implode(' ');
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
