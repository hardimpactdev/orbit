<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Enums\ProcessEventType;
use App\Models\ProcessEvent;
use App\Services\Processes\ProcessOwnerContext;
use App\Services\Processes\ProcessRuntimeTargets;
use Throwable;

final readonly class RestartProcesses
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
        $restarted = 0;

        foreach ($this->runtimeTargets->for($context, $name) as $target) {
            $process = $target['process'];
            $runtimeUnit = $target['runtime_unit'];
            $workspace = $context->runtimeWorkspaceFor($process);

            $events = [
                $this->eventPayload($this->recordProcessEvent->handle(
                    ProcessEventType::Restarting,
                    $context->eventApp(),
                    $workspace,
                    $process,
                    $context->node,
                    $runtimeUnit,
                )),
            ];

            try {
                $ok = $target['driver']->restart($context->node, $runtimeUnit);
            } catch (Throwable $exception) {
                $events[] = $this->eventPayload($this->recordProcessEvent->handle(
                    ProcessEventType::Failed,
                    $context->eventApp(),
                    $workspace,
                    $process,
                    $context->node,
                    $runtimeUnit,
                ));

                throw $exception;
            }

            $terminal = $this->recordProcessEvent->handle(
                $ok ? ProcessEventType::Started : ProcessEventType::Failed,
                $context->eventApp(),
                $workspace,
                $process,
                $context->node,
                $runtimeUnit,
            );
            $events[] = $this->eventPayload($terminal);

            if ($ok) {
                $restarted++;
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
                'events' => $events,
                ...($ok ? [] : ['message' => 'The runtime backend reported a restart failure.']),
            ];
        }

        return [
            'data' => ['runtimes' => $runtimes],
            'failed' => $failed,
            'message' => 'The runtime unit could not be restarted.',
            'meta' => [
                'process' => $name,
                'partial_state' => $restarted === 0 ? 'none_restarted' : 'partially_restarted',
            ],
        ];
    }

    /**
     * @return array{id: int, type: string}
     */
    private function eventPayload(ProcessEvent $event): array
    {
        return [
            'id' => $event->id,
            'type' => $event->event->value,
        ];
    }
}
