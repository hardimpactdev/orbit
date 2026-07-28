<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Enums\ProcessEventType;
use App\Services\Processes\ProcessOwnerContext;
use App\Services\Processes\ProcessRuntimeTargets;

final readonly class StopProcesses
{
    public function __construct(
        private ProcessRuntimeTargets $runtimeTargets,
        private RecordProcessEvent $recordProcessEvent,
    ) {}

    /**
     * @return array{data: array<string, mixed>, failed: bool, meta: array<string, mixed>, message: string}
     */
    public function handle(ProcessOwnerContext $context, ?string $name): array
    {
        $runtimes = [];
        $failed = false;
        $stopped = 0;

        foreach ($this->runtimeTargets->for($context, $name) as $target) {
            $process = $target['process'];
            $runtimeUnit = $target['runtime_unit'];
            $workspace = $context->runtimeWorkspaceFor($process);
            $ok = $target['driver']->stop($context->node, $runtimeUnit);
            $event = null;

            if ($ok) {
                $event = $this->recordProcessEvent->handle(
                    ProcessEventType::Stopped,
                    $context->eventApp(),
                    $workspace,
                    $process,
                    $context->node,
                    $runtimeUnit,
                );
                $stopped++;
            }

            $failed = $failed || ! $ok;
            $runtimes[] = [
                'process' => $process->name,
                'node' => $context->node->name,
                'project' => $context->app?->name,
                'instance' => $context->appInstance?->name,
                'workspace' => $workspace?->name,
                'runtime_unit' => $runtimeUnit,
                'state' => $ok ? 'stopped' : 'failed',
                'event' => $event === null
                    ? null
                    : [
                        'id' => $event->id,
                        'type' => $event->event->value,
                    ],
                ...($ok ? [] : ['message' => 'The runtime backend reported a stop failure.']),
            ];
        }

        return [
            'data' => ['runtimes' => $runtimes],
            'failed' => $failed,
            'message' => 'The runtime unit could not be stopped.',
            'meta' => [
                'process' => $name,
                'partial_state' => $stopped === 0 ? 'none_stopped' : 'partially_stopped',
            ],
        ];
    }
}
