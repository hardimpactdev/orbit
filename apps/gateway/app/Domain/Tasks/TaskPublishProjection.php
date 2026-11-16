<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Instances\Environment\DevelopmentCreateEnvironment;
use App\Domain\Instances\InstancePlacement;
use App\Domain\Instances\NestedFleetClubKey;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationWorkflowInput;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampLockTimeout;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Projects native publication metadata onto the board. No GitHub calls or execution. */
final readonly class TaskPublishProjection implements DirectOperationProjection, DirectOperationWorkflowInput
{
    /** The input key of why a publish of a named check opens no pull request: the Gateway's answer, never part of the run's intent. */
    public const string Withheld = 'pull_request_withheld';

    public function input(Operation $operation): array
    {
        return ['intent' => array_diff_key($operation->input, ['execution_secrets' => true, self::Withheld => true]),
            'execution_secrets' => $operation->input['execution_secrets']];
    }

    /**
     * A task copy's publish also gets the .env Orbit renders from the Instance's stored values, as a vault copy: the worker checks
     * the candidate against it as well as against the copy's own file, which the agent can change (docs/reference/tasks.md#task-copy).
     */
    public function workflowInput(Operation $operation): array
    {
        $input = $this->input($operation);
        if (! isset($operation->input['workspace'])) {
            return $input;
        }
        $instance = Instance::query()->with('runtime')->findOrFail(ValidatedData::integer($operation->input['instance_id']));
        $secrets = [...ValidatedData::object($input['execution_secrets']), 'environment' => app(SwampCommandStep::class)->executionCopy('orbit-control-inputs',
            'task-publish-environment-'.$operation->id, app(DevelopmentCreateEnvironment::class)->content($instance))];
        // A nested-fleet copy also holds the Club key in its /run: the check refuses it like a value of the .env.
        $club = $instance->runtime?->incus_project === InstancePlacement::NestedProject ? NestedFleetClubKey::reference() : null;
        if ($club === null) {
            return [...$input, 'execution_secrets' => $secrets];
        }
        $intent = ValidatedData::object($input['intent']);

        return ['intent' => [...$intent, 'workspace' => [...ValidatedData::object($intent['workspace']), 'club_key' => true]],
            'execution_secrets' => [...$secrets, 'club_key' => $club]];
    }

    /**
     * The base a task copy's publish states: the merge base of the checked commit and the default head at publish time, how far
     * behind, the paths both sides changed, bounded with the count beyond, and the default head the worker merged at publish or null
     * (docs/reference/tasks.md#merge-main-at-publish). A task VM publish, or a run that started on the workflow before the base
     * step, has no statement and records none of the fields; a run before the merge at publish records no `merged_default`.
     *
     * @return array<string,mixed>
     */
    private static function base(mixed $captured): array
    {
        if (! is_string($captured) || trim($captured) === '') {
            return [];
        }
        $facts = ValidatedData::object(json_decode($captured, true, flags: JSON_THROW_ON_ERROR));

        return array_intersect_key($facts, array_flip(['base_commit', 'default_commit', 'behind_by', 'overlapping_paths', 'overlapping_more', 'merged_default']));
    }

    /**
     * The paths of a refused conflict, from the check's line `tasks.publish_conflict: "a", "b" and N more`, wherever it stands in the
     * step's detail: at most 100 paths in at most 3,000 bytes, so the line fits the detail the Gateway keeps, then the count.
     *
     * @return array{conflicting_paths: list<string>, conflicting_more: int}
     */
    private static function conflict(string $detail): array
    {
        preg_match('/^tasks\.publish_conflict: (.*?)(?: and ([1-9][0-9]*) more)?$/m', $detail, $match);
        $paths = json_decode('['.($match[1] ?? '').']', true);

        return ['conflicting_paths' => is_array($paths) ? array_values(array_filter($paths, is_string(...))) : [], 'conflicting_more' => (int) ($match[2] ?? 0)];
    }

    /**
     * A refusal line `<code>: "a", "b" and N more`, wherever it stands in the step's detail: its code, and its quoted items, which name
     * paths, lines and secret names, never a value; null when the detail holds no such line.
     *
     * @return array{error_code: string, refused: list<string>, refused_more: int}|null
     */
    private static function refused(mixed $detail, string $code): ?array
    {
        if (! is_string($detail) || preg_match('/^'.preg_quote($code, '/').': (.*?)(?: and ([1-9][0-9]*) more)?$/m', $detail, $match) !== 1) {
            return null;
        }
        $items = json_decode('['.$match[1].']', true);

        return ['error_code' => $code, 'refused' => is_array($items) ? array_values(array_filter($items, is_string(...))) : [], 'refused_more' => (int) ($match[2] ?? 0)];
    }

    /**
     * The copy check's refusal (docs/reference/tasks.md#the-copy-check): a secret value with its items. Any other failure of the check
     * refuses as unchecked.
     *
     * @return array{error_code: string, refused?: list<string>, refused_more?: int}
     */
    private static function copyCheck(mixed $detail): array
    {
        return self::refused($detail, 'tasks.publish_secret_value') ?? ['error_code' => 'tasks.publish_secret_unchecked'];
    }

    /** Only the check's own refusal line names content; any other failure of the check refuses as unchecked. */
    private static function contentCode(mixed $detail): string
    {
        return is_string($detail) && preg_match('/^tasks\.publish_secret_content: /m', $detail) === 1
            ? 'tasks.publish_secret_content' : 'tasks.publish_secret_unchecked';
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $terminal = $operation->status->terminal();
            $native = ValidatedData::object($data['result'] ?? []);
            $status = OperationStatus::from(ValidatedData::string($data['status']));
            if ($status === OperationStatus::Succeeded) {
                $pull = isset($native['pull_receipt']) ? ValidatedData::object(json_decode(ValidatedData::string($native['pull_receipt']), true, flags: JSON_THROW_ON_ERROR)) : [];
                $result = ['branch' => $operation->input['branch'], 'commit' => $operation->input['commit'],
                    'pull_request_number' => $pull['pull_request_number'] ?? null, 'pull_request_url' => $pull['pull_request_url'] ?? null, ...self::base($native['base'] ?? null),
                    ...(isset($operation->input[self::Withheld]) ? [self::Withheld => $operation->input[self::Withheld]] : [])];
            } elseif (in_array($status, [OperationStatus::Failed, OperationStatus::Interrupted], true)) {
                $failure = ValidatedData::object($data['failure'] ?? []);
                $detail = $failure['detail'] ?? null;
                $step = $failure['failed_step'] ?? null;
                $locked = SwampLockTimeout::matches($detail);
                // The copy check refuses a secret value; the base check a workflow change that the default head lacks
                // (docs/reference/tasks.md#the-base-of-a-copy-publish). Each names its items.
                $refusal = in_array($step, ['Check the copy', 'Read the copy check'], true) && ! $locked ? self::copyCheck($detail)
                    : ($step === 'Check the base' && ! $locked ? self::refused($detail, 'tasks.publish_workflow_change') : null);
                $code = $refusal !== null ? $refusal['error_code'] : ($locked ? TaskDonePublication::LockTimeoutCode : ($step === 'Check the base' && is_string($detail) && preg_match('/^tasks\.publish_conflict: /m', $detail) === 1 ? 'tasks.publish_conflict' : (in_array($detail, ['tasks.head_moved', 'tasks.not_fast_forward', 'tasks.workflows_permission', 'tasks.push_failed', 'tasks.publish_failed', 'tasks.repository_execution_unknown'], true)
                    ? $detail : ($step === 'Check the environment file' ? 'tasks.publish_secret_path' : (in_array($step, ['Check the .env content', 'Read the .env check'], true) ? self::contentCode($detail) : ($step === 'Push the branch' ? 'tasks.push_failed' : (in_array($step, ['Init the bare repo', 'Import the bundle', 'Read bundle contents'], true) ? 'tasks.repository_execution_unknown' : 'tasks.publish_failed')))))));
                $data['error_code'] = $code;
                // A conflict names its paths, as the check's one line lists them.
                $result = ['error_code' => $code, ...($code === 'tasks.publish_conflict' ? self::conflict(ValidatedData::string($detail)) : []), ...array_diff_key($refusal ?? [], ['error_code' => true])];
            } else {
                $result = [];
            }
            $data['result'] = $result;
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if (! $terminal && $projected->status->value === 'succeeded' && isset($result['pull_request_url'])) {
                Task::topLevel()->whereKey($operation->target_id)->update([
                    'pr_url' => $result['pull_request_url'], 'watched_pr_url' => $result['pull_request_url'],
                    'watched_pr_number' => $result['pull_request_number'], 'watched_pr_state' => 'open',
                ]);
            }

            return $projected;
        });
    }
}
