<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Instances\InstanceContainer;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AgentThread;
use App\Models\InstanceRuntime;
use App\Models\Project;
use App\Models\Task;
use App\Rules\NotBlank;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\ValidationException;

/**
 * The agent of each role of a task copy turn: its provider, model and effort (docs/reference/tasks.md#copy-turn-agents). Each value
 * comes from the group's `<role>_agent_<value>` column, else from the Project's `task_<role>_<value>` default, else from the
 * Gateway's `orbit.tasks.copy_agents`, and reaches the provider as resolved: a role the provider cannot honour is refused
 * (self::refusal), when a request sets it and again at a turn's admission, never changed. The group's task VM columns
 * `implementer_model` and `reviewer_model` are never read here.
 * The provider and the effort pick the copy's T3 provider instance, which Orbit writes into the copy's T3 settings
 * (InstanceContainer::offered). What each provider accepts has one source, InstanceContainer: Claude's and Codex's efforts, and
 * Antigravity's models with the efforts of each. T3 offers Claude and Codex models in a catalog it fetches itself, so the turn's first
 * step checks such a model in the copy.
 */
final readonly class TaskAgent
{
    public const array Values = ['provider', 'model', 'effort'];

    /** A model slug as T3's `--model` takes it. */
    public const string ModelPattern = '/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D';

    /**
     * The request and response fields of the values: `implementer_provider` … for a group, `task_implementer_provider` … for a
     * Project with `task_`. A Project field is its column; a group field maps to its column through groupColumns().
     *
     * @return list<string>
     */
    public static function fields(string $prefix = ''): array
    {
        $columns = [];
        foreach (TaskThreadRole::cases() as $role) {
            foreach (self::Values as $value) {
                $columns[] = $prefix.$role->value.'_'.$value;
            }
        }

        return $columns;
    }

    /** @return array<string, list<string|In|NotBlank>> */
    public static function rules(string $prefix = ''): array
    {
        $rules = [];
        foreach (TaskThreadRole::cases() as $role) {
            $key = $prefix.$role->value.'_';
            $rules[$key.'provider'] = ['sometimes', new NotBlank, 'nullable', 'string', Rule::in(array_keys(InstanceContainer::CopyProviders))];
            $rules[$key.'model'] = ['sometimes', new NotBlank, 'nullable', 'string', 'max:128', 'regex:'.self::ModelPattern];
            $rules[$key.'effort'] = ['sometimes', new NotBlank, 'nullable', 'string', Rule::in(array_values(array_unique(array_merge(...array_values(InstanceContainer::CopyProviders)))))];
        }

        return $rules;
    }

    /**
     * The values a validated request sends, keyed by field; null clears one.
     *
     * @param  array<array-key, mixed>  $validated
     * @return array<string, string|null>
     */
    public static function provided(array $validated, string $prefix = ''): array
    {
        $provided = [];
        foreach (self::fields($prefix) as $field) {
            if (array_key_exists($field, $validated)) {
                $provided[$field] = is_string($validated[$field]) ? $validated[$field] : null;
            }
        }

        return $provided;
    }

    /** The group column of a role's value: `implementer_agent_model` for `implementer` and `model`. */
    public static function groupColumn(TaskThreadRole $role, string $value): string
    {
        return $role->value.'_agent_'.$value;
    }

    /**
     * Group values keyed by field, as provided() reads them, keyed by group column instead.
     *
     * @param  array<string, string|null>  $provided
     * @return array<string, string|null>
     */
    public static function groupColumns(array $provided): array
    {
        $columns = [];
        foreach (TaskThreadRole::cases() as $role) {
            foreach (self::Values as $value) {
                if (array_key_exists($role->value.'_'.$value, $provided)) {
                    $columns[self::groupColumn($role, $value)] = $provided[$role->value.'_'.$value];
                }
            }
        }

        return $columns;
    }

    /**
     * Each value of the role and where it comes from: `group`, `project` or `gateway`.
     *
     * @return array{provider: array{value: string, source: string}, model: array{value: string, source: string}, effort: array{value: string, source: string}}
     */
    public static function resolve(Task $group, TaskThreadRole $role): array
    {
        return self::resolveFrom($group->project, $role, static fn (string $value): mixed => $group->getAttribute(self::groupColumn($role, $value)));
    }

    /**
     * Each value of the role as the Project's defaults and the Gateway's resolve it, with no group.
     *
     * @return array{provider: array{value: string, source: string}, model: array{value: string, source: string}, effort: array{value: string, source: string}}
     */
    public static function resolveProject(Project $project, TaskThreadRole $role): array
    {
        return self::resolveFrom($project, $role, static fn (): mixed => null);
    }

    /**
     * Why the provider cannot honour the role's resolved values, keyed by the field of the value it names and with that value's source;
     * null when it can. Claude and Codex take their own efforts and any model, which the copy's T3 checks; Antigravity takes its models,
     * each at its own efforts (InstanceContainer::AntigravityModels).
     *
     * @param  array{provider: array{value: string, source: string}, model: array{value: string, source: string}, effort: array{value: string, source: string}}  $resolved
     * @return array{value: string, source: string, message: string}|null
     */
    public static function refusal(TaskThreadRole $role, array $resolved): ?array
    {
        ['provider' => $provider, 'model' => $model, 'effort' => $effort] = $resolved;
        $named = static fn (string $value, array $of): string => "The {$role->value} {$value} {$of['value']} (from the "
            .match ($of['source']) {
                'project' => 'Project', 'gateway' => 'Gateway', default => $of['source']
            }.')';
        $refused = static fn (string $value, string $message): array => ['value' => $value, 'source' => $resolved[$value]['source'], 'message' => $message];
        $efforts = InstanceContainer::CopyProviders[$provider['value']] ?? null;
        if ($efforts === null) {
            return $refused('provider', $named('provider', $provider).' is no task copy provider; the providers are '.self::listed(array_keys(InstanceContainer::CopyProviders)).'.');
        }
        if ($provider['value'] === 'antigravity') {
            $efforts = InstanceContainer::AntigravityModels[$model['value']] ?? null;
            if ($efforts === null) {
                return $refused('model', $named('model', $model).' is no Antigravity model; antigravity accepts '.self::listed(array_keys(InstanceContainer::AntigravityModels))
                    .". Set the {$role->value} model.");
            }
            if (! in_array($effort['value'], $efforts, true)) {
                return $refused('effort', $named('effort', $effort)." is not offered for {$model['value']} on antigravity; it accepts ".self::listed($efforts).'.');
            }

            return null;
        }
        if (! in_array($effort['value'], $efforts, true)) {
            return $refused('effort', $named('effort', $effort)." is no {$provider['value']} effort; {$provider['value']} accepts ".self::listed($efforts).'.');
        }

        return null;
    }

    /**
     * Refuses, as a validation failure on the field of the refused value, a role whose resolved values its provider cannot honour
     * (self::refusal). A request checks only the roles it sets, keyed by field in `$provided`: a value stored before the rule surfaces
     * at the turn's admission.
     *
     * @param  array<string, string|null>  $provided
     */
    public static function assertGroupHonours(Task $group, array $provided): void
    {
        self::assertHonoured($provided, '', static fn (TaskThreadRole $role): array => self::resolve($group, $role));
    }

    /**
     * The Project's own defaults as self::assertGroupHonours checks a group's: each role `$provided` sets resolves from the Project and
     * the Gateway. A group's role that a changed default leaves unhonoured is refused at its turn's admission.
     *
     * @param  array<string, string|null>  $provided  keyed by `task_` field
     */
    public static function assertProjectHonours(Project $project, array $provided): void
    {
        self::assertHonoured($provided, 'task_', static fn (TaskThreadRole $role): array => self::resolveProject($project, $role));
    }

    /**
     * The agent a new copy thread of the role starts with, in the copy of `$runtime`. A provider and effort the copy's T3 has no
     * instance for, such as Antigravity in a copy made before it, is refused here, before Swamp runs anything, with what the copy offers;
     * so is a role its provider cannot honour, such as one whose Project default changed since the group was set (self::refusal).
     * `model` and `effort` are the role's, as the thread records them; `t3_model` is the model the thread starts on in T3
     * (InstanceContainer::providerModel).
     *
     * @return array{provider: string, instance: string, model: string, t3_model: string, effort: string, options: list<array{id: string, value: string}>}
     */
    public static function forNewThread(Task $group, TaskThreadRole $role, InstanceRuntime $runtime): array
    {
        $resolved = self::resolve($group, $role);
        ['provider' => ['value' => $provider], 'model' => ['value' => $model], 'effort' => ['value' => $effort]] = $resolved;
        $refusal = self::refusal($role, $resolved);
        if ($refusal !== null) {
            throw new ResourceOperationException('tasks.agent_unsupported', $refusal['message'], 422,
                details: ['role' => $role->value, 'field' => $role->value.'_'.$refusal['value'], 'source' => $refusal['source']]);
        }
        $offered = InstanceContainer::offered($runtime);
        if (! in_array($effort, $offered[$provider] ?? [], true)) {
            throw new ResourceOperationException('tasks.copy_turn_unoffered', "The task copy's T3 offers no provider instance for {$provider} at {$effort} effort.", 422,
                details: ['role' => $role->value, 'provider' => $resolved['provider'], 'effort' => $resolved['effort'], 'offered' => $offered]);
        }
        if (preg_match(self::ModelPattern, $model) !== 1) {
            throw new ResourceOperationException('tasks.copy_turn_model_invalid', 'ORBIT_TASKS_COPY_'.strtoupper($role->value).'_MODEL must be a model name.', 503);
        }

        return ['provider' => $provider, 'instance' => InstanceContainer::providerInstance($provider, $effort), 'model' => $model,
            't3_model' => InstanceContainer::providerModel($provider, $model, $effort), 'effort' => $effort, 'options' => InstanceContainer::providerOptions($provider, $effort)];
    }

    /**
     * Refuses a change to a role's values once the role has a thread: the thread keeps the values it started with. The caller holds
     * the group's execution lock, which a turn's admission takes too.
     *
     * @param  array<string, string|null>  $changes  keyed by field
     */
    public static function assertChangeable(Task $group, array $changes): void
    {
        foreach (TaskThreadRole::cases() as $role) {
            $changed = array_filter(self::Values, static fn (string $value): bool => array_key_exists($role->value.'_'.$value, $changes));
            if ($changed !== [] && AgentThread::query()->where('task_group_id', $group->id)->where('role', $role->value)->exists()) {
                throw new ResourceOperationException('tasks.agent_settings_locked', "The {$role->value} has had a turn; its provider, model and effort stay as its thread started.", 409,
                    details: ['role' => $role->value]);
            }
        }
    }

    /**
     * @param  array<string, string|null>  $provided
     * @param  callable(TaskThreadRole): array{provider: array{value: string, source: string}, model: array{value: string, source: string}, effort: array{value: string, source: string}}  $resolve
     */
    private static function assertHonoured(array $provided, string $prefix, callable $resolve): void
    {
        $messages = [];
        foreach (TaskThreadRole::cases() as $role) {
            $set = array_filter(self::Values, static fn (string $value): bool => array_key_exists($prefix.$role->value.'_'.$value, $provided));
            $refusal = $set === [] ? null : self::refusal($role, $resolve($role));
            if ($refusal !== null) {
                $messages[$prefix.$role->value.'_'.$refusal['value']] = [$refusal['message']];
            }
        }
        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }

    /** @param  list<string>  $values */
    private static function listed(array $values): string
    {
        return implode(', ', $values);
    }

    /**
     * @param  callable(string): mixed  $grouped
     * @return array{provider: array{value: string, source: string}, model: array{value: string, source: string}, effort: array{value: string, source: string}}
     */
    private static function resolveFrom(Project $project, TaskThreadRole $role, callable $grouped): array
    {
        $resolved = [];
        foreach (self::Values as $value) {
            $group = $grouped($value);
            $projected = $project->getAttribute('task_'.$role->value.'_'.$value);
            $resolved[$value] = match (true) {
                is_string($group) => ['value' => $group, 'source' => 'group'],
                is_string($projected) => ['value' => $projected, 'source' => 'project'],
                default => ['value' => config()->string('orbit.tasks.copy_agents.'.$role->value.'.'.$value), 'source' => 'gateway'],
            };
        }

        /** @var array{provider: array{value: string, source: string}, model: array{value: string, source: string}, effort: array{value: string, source: string}} $resolved */
        return $resolved;
    }
}
