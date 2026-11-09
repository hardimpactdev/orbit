<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Tasks\ShowTasksStatusRequest;
use Orbit\Sdk\Responses\Tasks\TaskAssistanceResponse;
use Orbit\Sdk\Responses\Tasks\TaskCapacityResponse;
use Orbit\Sdk\Responses\Tasks\TaskMergeResponse;
use Orbit\Sdk\Responses\Tasks\TaskPlaceQueueResponse;
use Orbit\Sdk\Responses\Tasks\TasksStatusResponse;

final class ShowTasksStatusCommand extends TaskCommand
{
    #[\Override]
    protected $signature = 'task:status
        {--json : Return machine-readable JSON}';

    public function extensionSlug(): ?string
    {
        return null;
    }

    #[\Override]
    protected $description = 'Show whether the Gateway tasks extension is on, which groups are asking for assistance, which review-and-merge pull requests are open, and the task places of each host.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $status = $this->sendWithProgress($connector, new ShowTasksStatusRequest, TasksStatusResponse::class, ['Show tasks status', 'Loading tasks status', 'Loaded tasks status']);

        if (! $status instanceof TasksStatusResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($status->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail('Extension: tasks', [
            'Status' => $status->enabled ? 'enabled' : 'disabled',
        ]));
        $this->renderAssistance($status->assistance ?? []);
        $this->renderMerges($status->merges);
        $this->renderCapacity($status->capacity);
        $this->writeHumanMessage("Request ID: {$status->requestId}");

        return self::SUCCESS;
    }

    /** @param list<TaskAssistanceResponse> $groups */
    private function renderAssistance(array $groups): void
    {
        $direction = [];
        $failures = [];

        foreach ($groups as $group) {
            if ($group->assistanceKind === 'direction') {
                $direction[] = $group;
            } else {
                $failures[] = $group;
            }
        }

        if ($direction === [] && $failures === []) {
            $this->writeHumanMessage('No groups are asking for assistance.');

            return;
        }

        if ($direction !== []) {
            $this->writeSection('Needs your direction');
            ConsoleWriter::write($this->output, $this->humanRenderer()->table(
                ['Group', 'Title', 'Status', 'Question'],
                array_map(static fn (TaskAssistanceResponse $group): array => [
                    $group->reference(),
                    $group->title,
                    $group->status,
                    $group->assistanceQuestion,
                ], $direction),
            ));
        }

        if ($failures !== []) {
            $this->writeSection('Failures');
            ConsoleWriter::write($this->output, $this->humanRenderer()->table(
                ['Group', 'Title', 'Status', 'Reason'],
                array_map(static fn (TaskAssistanceResponse $group): array => [
                    $group->reference(),
                    $group->title,
                    $group->status,
                    $group->assistanceReason,
                ], $failures),
            ));
        }
    }

    /** @param list<TaskMergeResponse> $groups */
    private function renderMerges(array $groups): void
    {
        if ($groups === []) {
            return;
        }

        $this->writeSection('Review and merge');
        ConsoleWriter::write($this->output, $this->humanRenderer()->table(
            ['Group', 'Title', 'Status', 'Merge', 'Reason'],
            array_map(static fn (TaskMergeResponse $group): array => [
                $group->reference(),
                $group->title,
                $group->status,
                $group->mergeStatus,
                $group->mergeReason,
            ], $groups),
        ));
    }

    private function renderCapacity(TaskCapacityResponse $capacity): void
    {
        if ($capacity->hosts !== []) {
            $this->writeSection('Task places');
            $rows = [];
            foreach ($capacity->hosts as $host) {
                foreach ($host->holders as $holder) {
                    $rows[] = [$host->node, $holder->pool, $holder->vm, $holder->groupId === null ? '—' : "#{$holder->groupId}", $holder->status ?? '—',
                        $holder->lastActivityAt ?? '—', $holder->activeOperationId ?? '—'];
                }
                foreach (['task' => $host->taskFree, 'ci' => $host->ciFree] as $pool => $free) {
                    for ($place = 0; $place < $free; $place++) {
                        $rows[] = [$host->node, $pool, 'free', '—', '—', '—', '—'];
                    }
                }
            }
            ConsoleWriter::write($this->output, $this->humanRenderer()->table(['Host', 'Pool', 'VM', 'Group', 'Status', 'Last activity', 'Open operation'], $rows));
        }

        if ($capacity->queue !== []) {
            $this->writeSection('Waiting for a place');
            ConsoleWriter::write($this->output, $this->humanRenderer()->table(
                ['Position', 'Group', 'Title', 'Operation', 'Kind', 'Waiting since'],
                array_map(static fn (TaskPlaceQueueResponse $entry): array => [
                    $entry->position, "#{$entry->groupId}", $entry->title ?? '—', $entry->operationId, $entry->kind, $entry->waitingSince ?? '—',
                ], $capacity->queue),
            ));
        }
    }

    private function writeSection(string $heading): void
    {
        $this->writeHumanMessage($heading);
        ConsoleWriter::write($this->output, "\n");
    }
}
