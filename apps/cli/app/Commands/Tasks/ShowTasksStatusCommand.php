<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Tasks\ShowTasksStatusRequest;
use Orbit\Sdk\Responses\Tasks\TaskAssistanceResponse;
use Orbit\Sdk\Responses\Tasks\TaskMergeResponse;
use Orbit\Sdk\Responses\Tasks\TasksStatusResponse;
use Orbit\Sdk\Responses\Tasks\TaskWorkspaceActorResponse;
use Orbit\Sdk\Responses\Tasks\TaskWorkspaceLeaseResponse;

final class ShowTasksStatusCommand extends TaskCommand
{
    #[\Override]
    protected $signature = 'tasks:status
        {--json : Return machine-readable JSON}';

    #[\Override]
    public function isHidden(): bool
    {
        return false;
    }

    public function extensionSlug(): ?string
    {
        return null;
    }

    #[\Override]
    protected $description = 'Show whether the Gateway tasks extension is on, which groups are asking for assistance, which review-and-merge groups are open, and who holds or waits for workspace leases.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $connector = $this->coreGatewayConnector($repository, $connectors);

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
            'Last tick' => $status->lastTickAt ?? 'not recorded',
        ]));
        $this->renderAssistance($status->assistance ?? []);
        $this->renderMerges($status->merges);
        $this->renderWorkspaceLeases($status->workspaceLeases);
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

    /** @param list<TaskWorkspaceLeaseResponse> $leases */
    private function renderWorkspaceLeases(array $leases): void
    {
        if ($leases === []) {
            return;
        }
        $this->writeSection('Workspace leases');
        foreach ($leases as $lease) {
            $holder = $lease->holder;
            ConsoleWriter::write($this->output, $this->humanRenderer()->detail($lease->instanceName.' ('.$lease->reference().')', [
                'Holder' => $holder === null ? 'none' : $holder->kind.' ['.$holder->owner.']',
                'Acquired' => $holder->acquiredAt ?? 'not recorded',
                'Thread' => $holder?->threadId,
                'Check' => $holder?->checkId,
                'Remote result' => $holder?->remoteUncertain === true ? 'uncertain; lease retained' : 'no uncertainty recorded',
            ]));
            if ($lease->waiters === []) {
                $this->writeHumanMessage('No queued workspace actors.');

                continue;
            }
            ConsoleWriter::write($this->output, $this->humanRenderer()->table(
                ['Kind', 'Owner', 'Queued', 'Thread', 'Check'],
                array_map(static fn (TaskWorkspaceActorResponse $actor): array => [
                    $actor->kind, $actor->owner, $actor->queuedAt, $actor->threadId, $actor->checkId,
                ], $lease->waiters),
            ));
        }
    }

    private function writeSection(string $heading): void
    {
        $this->writeHumanMessage($heading);
        ConsoleWriter::write($this->output, "\n");
    }
}
