<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Actions\Processes\ShowProcessLogs;
use App\Models\App;
use App\Models\Node;
use App\Models\Process as ProcessModel;
use App\Models\Workspace;
use App\Services\Processes\ProcessOwnerContext;
use App\Services\Processes\ProcessOwnerContextResolver;
use App\Services\RemoteShell\RemoteLocalExecutorTransportFailed;
use Illuminate\Support\Facades\Process;
use Orbit\Sdk\Laravel\GatewayApiException;

final readonly class ToolLogReader
{
    public function __construct(
        private ToolCatalog $catalog,
        private ToolRuntimeResolver $runtimes,
        private ToolScriptDispatcher $toolScriptDispatcher,
        private ProcessOwnerContextResolver $processContexts,
        private ShowProcessLogs $processLogs,
    ) {}

    /**
     * @return array<string, mixed>|ToolRegistryFailure
     */
    public function read(
        string $tool,
        ?string $node = null,
        ?string $app = null,
        int $lines = 100,
    ): array|ToolRegistryFailure {
        $target = $this->runtimes->resolve($tool, 'logs', $node, $app);

        if ($target instanceof ToolRegistryFailure) {
            return $target;
        }

        return $this->readTarget($target, $lines);
    }

    /**
     * @return array<string, mixed>|ToolRegistryFailure
     */
    private function readTarget(ToolRuntimeTarget $target, int $lines): array|ToolRegistryFailure
    {
        $tool = $target->tool->name;

        if ($target->process instanceof ProcessModel) {
            try {
                $result = $this->processLogs->handle(
                    $this->contextFor($target->process),
                    $target->process->name,
                    $lines,
                );
            } catch (RemoteLocalExecutorTransportFailed $exception) {
                return ToolRegistryFailure::agentUnreachable(
                    message: "Orbit Agent is unreachable for tool '{$tool}' logs on node '{$target->node->name}'.",
                    meta: [
                        'reason' => 'agent_push_unavailable',
                        'node' => $target->node->name,
                        'platform' => (string) $target->node->platform,
                        'tool' => $tool,
                        'action' => 'logs',
                        'error' => $exception->getMessage(),
                    ],
                );
            } catch (GatewayApiException $exception) {
                return ToolRegistryFailure::remoteActionFailed(
                    $tool,
                    $target->node->name,
                    'logs',
                    1,
                    $exception->getMessage(),
                );
            }

            return [
                ...$result['data']['logs'],
                'tool' => $tool,
                'runtime' => 'process',
            ];
        }

        $script = $this->catalog->logCommand($tool, $lines);

        if ($script === null) {
            return ToolRegistryFailure::unsupportedAction($tool, 'logs');
        }

        if ($this->catalog->gatewayLocal($tool)) {
            $result = Process::timeout(60)->run($script);

            if (! $result->successful()) {
                return ToolRegistryFailure::remoteActionFailed(
                    $tool,
                    $target->node->name,
                    'logs',
                    $result->exitCode() ?? 1,
                    $this->failureOutput($result->output(), $result->errorOutput()),
                );
            }

            $output = $result->output();
        } else {
            $result = $this->toolScriptDispatcher->runForRegistry(
                node: $target->node,
                tool: $tool,
                action: 'logs',
                script: $script,
            );

            if ($result instanceof ToolRegistryFailure) {
                return $result;
            }

            if (! $result->successful()) {
                return ToolRegistryFailure::remoteActionFailed(
                    $tool,
                    $target->node->name,
                    'logs',
                    $result->exitCode,
                    $this->failureOutput($result->stdout, $result->stderr),
                );
            }

            $output = $result->stdout;
        }

        return [
            'tool' => $tool,
            'node' => $target->node->name,
            'runtime' => 'tool',
            'lines' => $this->lines($output),
        ];
    }

    private function contextFor(ProcessModel $process): ProcessOwnerContext
    {
        $process->loadMissing('owner');

        if ($process->owner instanceof Node) {
            return $this->processContexts->resolve($process->owner->name, null, null);
        }

        if ($process->owner instanceof App) {
            return $this->processContexts->resolve(null, $process->owner->name, null);
        }

        if ($process->owner instanceof Workspace) {
            $process->owner->loadMissing('app');

            return $this->processContexts->resolve(
                null,
                $process->owner->app?->name,
                $process->owner->name,
            );
        }

        throw new GatewayApiException(
            "Process '{$process->name}' is not log-addressable.",
            'validation_failed',
            ['process' => $process->name],
        );
    }

    /**
     * Prefer stderr, but keep stdout when commands redirect with 2>&1 (for
     * example `docker logs ... 2>&1`) so failure reports stay useful.
     */
    private function failureOutput(string $stdout, string $stderr): string
    {
        $stderr = trim($stderr);

        if ($stderr !== '') {
            return $stderr;
        }

        return trim($stdout);
    }

    /**
     * @return list<array{message: string}>
     */
    private function lines(string $output): array
    {
        $lines = preg_split('/\R/', trim($output));

        if (! is_array($lines)) {
            return [];
        }

        return array_values(array_map(
            static fn (string $line): array => ['message' => $line],
            array_filter($lines, static fn (string $line): bool => $line !== ''),
        ));
    }
}
