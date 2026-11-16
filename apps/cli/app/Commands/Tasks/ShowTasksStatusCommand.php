<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Tasks\ShowTasksStatusRequest;
use Orbit\Sdk\Responses\Tasks\TaskAssistanceResponse;
use Orbit\Sdk\Responses\Tasks\TaskMergeResponse;
use Orbit\Sdk\Responses\Tasks\TaskReleaseResponse;
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
    protected $description = 'Show whether the Gateway tasks extension is on, which groups are asking for assistance, and which review-and-merge pull requests are open.';

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
        $this->renderReleases($status->releases);
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

    /**
     * Task copy groups whose pull request waits for `task:publish <task>`, or whose automatic publish was refused.
     *
     * @param  list<TaskReleaseResponse>  $groups
     */
    private function renderReleases(array $groups): void
    {
        if ($groups === []) {
            return;
        }

        $this->writeSection('Waiting for release');
        ConsoleWriter::write($this->output, $this->humanRenderer()->table(
            ['Group', 'Title', 'State', 'Code'],
            array_map(static fn (TaskReleaseResponse $group): array => [
                $group->reference(),
                $group->title,
                $group->state,
                $group->errorCode,
            ], $groups),
        ));
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

    private function writeSection(string $heading): void
    {
        $this->writeHumanMessage($heading);
        ConsoleWriter::write($this->output, "\n");
    }
}
