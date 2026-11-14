<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Instances\InstanceContainer;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AgentThread;
use App\Models\Task;
use App\Rules\NotBlank;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * The agent of each role of a task copy turn: its provider, model and effort (docs/reference/tasks.md#copy-turn-agents). Each value
 * comes from the group's `<role>_agent_<value>` column, else from the Project's `task_<role>_<value>` default, else from the
 * Gateway's `orbit.tasks.copy_agents`. The group's task VM columns `implementer_model` and `reviewer_model` are never read here.
 * The provider and the effort pick the copy's T3 provider instance, which Orbit writes into the copy's T3 settings
 * (InstanceContainer::CopyProviders).
 * T3 offers its models in a catalog it fetches itself, so the turn's first step checks the model in the copy.
 */
final readonly class TaskCopyAgent
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
        $resolved = [];
        foreach (self::Values as $value) {
            $grouped = $group->getAttribute(self::groupColumn($role, $value));
            $projected = $group->project->getAttribute('task_'.$role->value.'_'.$value);
            $resolved[$value] = match (true) {
                is_string($grouped) => ['value' => $grouped, 'source' => 'group'],
                is_string($projected) => ['value' => $projected, 'source' => 'project'],
                default => ['value' => config()->string('orbit.tasks.copy_agents.'.$role->value.'.'.$value), 'source' => 'gateway'],
            };
        }

        /** @var array{provider: array{value: string, source: string}, model: array{value: string, source: string}, effort: array{value: string, source: string}} $resolved */
        return $resolved;
    }

    /**
     * The agent a new copy thread of the role starts with. A provider and effort the copy's T3 has no instance for is refused here,
     * before Swamp runs anything, with what the copy offers.
     *
     * @return array{provider: string, instance: string, model: string, effort: string}
     */
    public static function forNewThread(Task $group, TaskThreadRole $role): array
    {
        $resolved = self::resolve($group, $role);
        ['provider' => ['value' => $provider], 'model' => ['value' => $model], 'effort' => ['value' => $effort]] = $resolved;
        if (! in_array($effort, InstanceContainer::CopyProviders[$provider] ?? [], true)) {
            throw new ResourceOperationException('tasks.copy_turn_unoffered', "The task copy's T3 offers no provider instance for {$provider} at {$effort} effort.", 422,
                details: ['role' => $role->value, 'provider' => $resolved['provider'], 'effort' => $resolved['effort'], 'offered' => InstanceContainer::CopyProviders]);
        }
        if (preg_match(self::ModelPattern, $model) !== 1) {
            throw new ResourceOperationException('tasks.copy_turn_model_invalid', 'ORBIT_TASKS_COPY_'.strtoupper($role->value).'_MODEL must be a model name.', 503);
        }

        return ['provider' => $provider, 'instance' => InstanceContainer::providerInstance($provider, $effort), 'model' => $model, 'effort' => $effort];
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
}
