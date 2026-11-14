<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Commands\Concerns\ReadsTaskAgentOptions;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ProgressOutcome;
use App\Support\Console\ProgressState;
use JsonException;
use Orbit\Sdk\Requests\Tasks\CreateTaskRequest;
use Orbit\Sdk\Requests\Tasks\SubtaskInput;
use Orbit\Sdk\Responses\Tasks\SubtaskResponse;
use Orbit\Sdk\Responses\Tasks\TaskGroupResponse;
use Orbit\Sdk\Responses\Tasks\TaskRecordResponse;

final class CreateTaskGroupCommand extends TaskCommand
{
    use ReadsTaskAgentOptions;

    /** The Gateway stores at most this many subtasks on create. */
    private const int MAX_SUBTASKS = 50;

    #[\Override]
    protected $signature = 'task:create
        {title? : Short name of the feature}
        {--project= : Numeric Project ID}
        {--parent= : Numeric parent Task ID}
        {--deliverables= : JSON file with typed child deliverables}
        {--topology= : JSON array of required workload nodes}
        {--brief= : Goal and acceptance}
        {--status= : backlog (default) or todo}
        {--orchestration= : external (default; Swamp executes task groups)}
        {--subtasks= : JSON file with an ordered array of objects that each hold a title, a brief, and optional deliverables}
        {--preview : Keep the sandbox running during review}
        {--current-recipe : Wait for the current machine recipe image; never start from a fallback image}
        {--review= : manual (default; Nick approves the head) or automatic (a fresh reviewer passes the head)}
        {--implementer-provider= : Implementer provider in a task copy, such as claude}
        {--implementer-model= : Implementer model in a task copy, such as claude-opus-5-5}
        {--implementer-effort= : Implementer effort in a task copy: low, medium, high, xhigh, or max}
        {--reviewer-provider= : Reviewer provider in a task copy}
        {--reviewer-model= : Reviewer model in a task copy}
        {--reviewer-effort= : Reviewer effort in a task copy}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Create a task or subtask.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $child = $this->option('parent') !== null;
        $unsupported = $child
            ? ['status', 'orchestration', 'subtasks', 'review', 'preview', 'current-recipe', 'implementer-provider', 'implementer-model', 'implementer-effort',
                'reviewer-provider', 'reviewer-model', 'reviewer-effort']
            : ['deliverables', 'topology'];
        foreach ($unsupported as $option) {
            if (! in_array($this->option($option), [null, false], true)) {
                return $this->renderGatewayFailure('task.option_invalid', "--{$option} is only available when creating ".($child ? 'a parent task.' : 'a subtask.'));
            }
        }

        if ($this->option('parent') !== null) {
            return $this->createChild($repository, $connectors);
        }

        $orchestration = $this->option('orchestration');
        if ($orchestration !== null && ! in_array($orchestration, ['external'], true)) {
            return $this->renderGatewayFailure('task.orchestration_invalid', 'Orchestration must be external.');
        }
        $review = $this->option('review');
        if ($review !== null && ! in_array($review, ['manual', 'automatic'], true)) {
            return $this->renderGatewayFailure('task.review_invalid', 'Review must be manual or automatic.');
        }
        $status = $this->option('status');

        if ($status !== null && ! in_array($status, self::PLANNING_STATUSES, true)) {
            return $this->renderGatewayFailure('task.status_invalid', 'Status must be one of '.implode(', ', self::PLANNING_STATUSES).'.');
        }

        $subtasks = $this->subtasks();

        if ($subtasks === false) {
            return self::FAILURE;
        }

        $agents = $this->taskAgentsOption('', 'task', clearable: false);

        if ($agents === false) {
            return self::FAILURE;
        }

        $projectId = $this->idOption('project', 'Project', 'project');

        if ($projectId === false) {
            return self::FAILURE;
        }

        $title = $this->textInput($this->argument('title'), 'Title', 'title', self::TITLE_MAX);

        if ($title === false) {
            return self::FAILURE;
        }

        $brief = $this->textInput($this->option('brief'), 'Brief', 'brief', self::BRIEF_MAX);

        if ($brief === false) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $projectId ??= $this->selectProject($connector);

        if ($projectId === null) {
            return self::FAILURE;
        }

        $title ??= $this->promptText('Title', self::TITLE_MAX);
        $brief ??= $this->promptText('Brief', self::BRIEF_MAX, multiline: true);

        $group = $this->sendWithProgress(
            $connector,
            new CreateTaskRequest(
                projectId: $projectId,
                title: $title,
                brief: $brief,
                status: is_string($status) ? $status : null,
                subtasks: $subtasks,
                preview: $this->option('preview') === true ? true : null,
                orchestration: is_string($orchestration) ? $orchestration : null,
                currentRecipe: $this->option('current-recipe') === true ? true : null,
                review: is_string($review) ? $review : null,
                agents: $agents,
            ),
            TaskRecordResponse::class,
            ['Create task group', 'Creating task group', 'Created task group'],
            static fn (TaskRecordResponse $group): ProgressState|ProgressOutcome => $group instanceof TaskGroupResponse && $group->status === 'failed'
                ? new ProgressOutcome(ProgressState::Warning, 'Created task group, but it failed to start')
                : ProgressState::Success,
        );

        return $group instanceof SubtaskResponse ? $this->renderSubtask($group) : ($group instanceof TaskGroupResponse ? $this->renderGroup($group) : self::FAILURE);
    }

    /**
     * Reads the --subtasks file. Returns null when the option is absent and false after the refusal.
     *
     * @return list<SubtaskInput>|false|null
     */
    private function subtasks(): array|false|null
    {
        $path = $this->option('subtasks');

        if ($path === null) {
            return null;
        }

        $refusal = 'The subtasks file must hold a JSON array of at most '.self::MAX_SUBTASKS.' objects, each with a title and a brief, and optionally a deliverables array of at most '.self::DELIVERABLES_MAX.' objects with string fields.';
        $contents = $path !== '' && is_file($path) && is_readable($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            $this->renderGatewayFailure('task.subtasks_invalid', 'The subtasks file cannot be read.');

            return false;
        }

        try {
            $entries = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $entries = null;
        }

        if (! is_array($entries) || ! array_is_list($entries) || count($entries) > self::MAX_SUBTASKS) {
            $this->renderGatewayFailure('task.subtasks_invalid', $refusal);

            return false;
        }

        $subtasks = [];

        foreach ($entries as $entry) {
            $title = is_array($entry) ? ($entry['title'] ?? null) : null;
            $brief = is_array($entry) ? ($entry['brief'] ?? null) : null;
            $deliverables = self::deliverables(is_array($entry) ? ($entry['deliverables'] ?? []) : null);
            $topology = self::topology(is_array($entry) && array_key_exists('topology', $entry) ? $entry['topology'] : []);

            if (
                ! is_string($title) || ! is_string($brief) || $deliverables === null || $topology === null
                || self::textError($title, 'Title', self::TITLE_MAX) !== null
                || self::textError($brief, 'Brief', self::BRIEF_MAX) !== null
            ) {
                $this->renderGatewayFailure('task.subtasks_invalid', self::$deliverablesRefusal ?? $refusal);

                return false;
            }

            $subtasks[] = new SubtaskInput($title, $brief, $deliverables, array_key_exists('topology', $entry) ? $topology : null);
        }

        return $subtasks;
    }

    private function createChild(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $projectId = $this->option('project') === null ? null : $this->idOption('project', 'Project', 'project');
        if ($projectId === false) {
            return self::FAILURE;
        }

        $groupId = $this->idOption('parent', 'Parent task', 'parent');

        if ($groupId === false) {
            return self::FAILURE;
        }

        $title = $this->textInput($this->argument('title'), 'Title', 'title', self::TITLE_MAX);

        if ($title === false) {
            return self::FAILURE;
        }

        $brief = $this->textInput($this->option('brief'), 'Brief', 'brief', self::BRIEF_MAX);

        if ($brief === false) {
            return self::FAILURE;
        }

        $topology = $this->topologyOption($this->option('topology'));
        if ($topology === false) {
            return self::FAILURE;
        }

        $deliverables = $this->deliverablesFile();

        if ($deliverables === false) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $groupId ??= $this->selectGroup($connector);

        if ($groupId === null) {
            return self::FAILURE;
        }

        $title ??= $this->promptText('Title', self::TITLE_MAX);
        $brief ??= $this->promptText('Brief', self::BRIEF_MAX, multiline: true);

        $task = $this->sendWithProgress($connector, new CreateTaskRequest(projectId: $projectId, title: $title, brief: $brief, parentId: $groupId, deliverables: $deliverables, topology: $topology), SubtaskResponse::class, ['Create subtask', 'Creating subtask', 'Created subtask']);

        return $task instanceof SubtaskResponse ? $this->renderSubtask($task) : self::FAILURE;
    }
}
