<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Enums\ProcessEventType;
use App\Models\ProcessEvent;
use App\Services\Processes\ProcessOwnerContext;
use App\Services\Processes\ProcessRuntimeTargets;
use Throwable;

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

            $events = [
                $this->eventPayload($this->recordProcessEvent->handle(
                    ProcessEventType::Starting,
                    $context->eventApp(),
                    $workspace,
                    $process,
                    $context->node,
                    $runtimeUnit,
                )),
            ];

            try {
                $ok = $target['driver']->start($context->node, $runtimeUnit);
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
                $started++;
            }

            $failed = $failed || ! $ok;
            $runtimes[] = [
                'process' => $process->name,
                'node' => $context->node->name,
                'app' => $context->app?->name,
                'instance' => $context->instance?->name,
                'workspace' => $workspace?->name,
                'runtime_unit' => $runtimeUnit,
                'state' => $ok ? 'running' : 'failed',
                'event' => $this->eventPayload($terminal),
                'events' => $events,
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
