<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Actions\Processes\RestartProcesses;
use App\Actions\Processes\StartProcesses;
use App\Actions\Processes\StopProcesses;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\Processes\ProcessOwnerContext;
use App\Services\Processes\ProcessOwnerContextResolver;
use App\Services\RemoteShell\RemoteLocalExecutorTransportFailed;
use Orbit\Sdk\Laravel\GatewayApiException;

final readonly class ProcessToolLifecycleRunner
{
    public function __construct(
        private ProcessOwnerContextResolver $processContexts,
        private StartProcesses $startProcesses,
        private StopProcesses $stopProcesses,
        private RestartProcesses $restartProcesses,
    ) {}

    /** @return array<string, mixed>|ToolRegistryFailure */
    public function run(ToolRuntimeTarget $target, string $action): array|ToolRegistryFailure
    {
        $process = $target->process;

        if (! $process instanceof Process) {
            return ToolRegistryFailure::runtimeMissing($target->tool->name, $target->node->name, $action);
        }

        if ($action === 'reload') {
            return ToolRegistryFailure::unsupportedAction($target->tool->name, $action);
        }

        try {
            $context = $this->contextFor($process);
            $result = match ($action) {
                'start' => $this->startProcesses->handle($context, $process->name),
                'stop' => $this->stopProcesses->handle($context, $process->name),
                'restart' => $this->restartProcesses->handle($context, $process->name),
                default => null,
            };
        } catch (RemoteLocalExecutorTransportFailed $exception) {
            return ToolRegistryFailure::agentUnreachable(
                message: "Orbit Agent is unreachable for tool '{$target->tool->name}' {$action} on node '{$target->node->name}'.",
                meta: [
                    'reason' => 'agent_push_unavailable',
                    'node' => $target->node->name,
                    'tool' => $target->tool->name,
                    'action' => $action,
                    'error' => $exception->getMessage(),
                ],
            );
        } catch (GatewayApiException $exception) {
            return ToolRegistryFailure::remoteActionFailed(
                $target->tool->name,
                $target->node->name,
                $action,
                1,
                $exception->getMessage(),
            );
        }

        if (! is_array($result)) {
            return ToolRegistryFailure::unsupportedAction($target->tool->name, $action);
        }

        if (($result['failed'] ?? false) === true) {
            return ToolRegistryFailure::remoteActionFailed(
                $target->tool->name,
                $target->node->name,
                $action,
                1,
                is_string($result['message'] ?? null) ? $result['message'] : 'The process runtime backend failed.',
            );
        }

        return [
            'name' => $target->tool->name,
            'node' => $target->node->name,
            'action' => $action,
            'runtime' => 'process',
            'process' => $process->name,
        ];
    }

    private function contextFor(Process $process): ProcessOwnerContext
    {
        $process->loadMissing('owner');

        if ($process->owner instanceof Node) {
            return $this->processContexts->resolve($process->owner->name, null, null);
        }

        if ($process->owner instanceof Project) {
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
            "Process '{$process->name}' is not lifecycle-addressable.",
            'validation_failed',
            ['process' => $process->name],
        );
    }
}
