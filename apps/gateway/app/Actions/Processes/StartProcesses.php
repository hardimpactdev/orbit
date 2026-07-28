<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Enums\ProcessEventType;
use App\Services\Processes\ProcessOwnerContext;
use App\Services\Processes\ProcessRuntimeTargets;

final readonly class StartProcesses
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
        $started = 0;

        foreach ($this->runtimeTargets->for($context, $name) as $target) {
            $process = $target['process'];
            $runtimeUnit = $target['runtime_unit'];
            $workspace = $context->runtimeWorkspaceFor($process);
            $ok = $target['driver']->start($context->node, $runtimeUnit);
            $event = null;

            if ($ok) {
                $event = $this->recordProcessEvent->handle(
                    ProcessEventType::Started,
                    $context->eventApp(),
                    $workspace,
                    $process,
                    $context->node,
                    $runtimeUnit,
                );
                $started++;
            }

            $failed = $failed || ! $ok;
            $runtimes[] = [
                'process' => $process->name,
                'node' => $context->node->name,
                'project' => $context->app?->name,
                'instance' => $context->appInstance?->name,
                'workspace' => $workspace?->name,
                'runtime_unit' => $runtimeUnit,
                'state' => $ok ? 'running' : 'failed',
                'event' => $event === null
                    ? null
                    : [
                        'id' => $event->id,
                        'type' => $event->event->value,
                    ],
                ...($ok ? [] : ['message' => 'The runtime backend reported a start failure.']),
            ];
        }

        return [
            'data' => ['runtimes' => $runtimes],
            'failed' => $failed,
            'message' => 'The runtime unit could not be started.',
            'meta' => [
                'process' => $name,
                'partial_state' => $started === 0 ? 'none_started' : 'partially_started',
            ],
        ];
    }
}
