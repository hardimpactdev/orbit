<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Instances\InstanceContainer;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AgentThread;
use App\Models\Task;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * The agent of each role of a task copy turn: its provider, model and effort (docs/reference/tasks.md#copy-turn-agents). Each value
 * comes from the group, else from the Project's default, else from the Gateway's `orbit.tasks.copy_agents`. The provider and the
 * effort pick the copy's T3 provider instance, which Orbit writes into the copy's T3 settings (InstanceContainer::CopyProviders).
 * T3 offers its models in a catalog it fetches itself, so the turn's first step checks the model in the copy.
 */
final readonly class TaskCopyAgent
{
    public const array Values = ['provider', 'model', 'effort'];

    /** A model slug as T3's `--model` takes it. */
    public const string ModelPattern = '/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D';

    /**
     * The columns of the values: `implementer_provider` … on a group, `task_implementer_provider` … on a Project with `task_`.
     *
     * @return list<string>
     */
    public static function columns(string $prefix = ''): array
    {
        $columns = [];
        foreach (TaskThreadRole::cases() as $role) {
            foreach (self::Values as $value) {
                $columns[] = $prefix.$role->value.'_'.$value;
            }
        }

        return $columns;
    }

    /** @return array<string, list<string|In>> */
    public static function rules(string $prefix = ''): array
    {
        $rules = [];
        foreach (TaskThreadRole::cases() as $role) {
            $key = $prefix.$role->value.'_';
            $rules[$key.'provider'] = ['sometimes', 'nullable', 'string', Rule::in(array_keys(InstanceContainer::CopyProviders))];
            $rules[$key.'model'] = ['sometimes', 'nullable', 'string', 'max:128', 'regex:'.self::ModelPattern];
            $rules[$key.'effort'] = ['sometimes', 'nullable', 'string', Rule::in(array_values(array_unique(array_merge(...array_values(InstanceContainer::CopyProviders)))))];
        }

        return $rules;
    }

    /**
     * The values a validated request sends, keyed by column; null clears one.
     *
     * @param  array<array-key, mixed>  $validated
     * @return array<string, string|null>
     */
    public static function provided(array $validated, string $prefix = ''): array
    {
        $provided = [];
        foreach (self::columns($prefix) as $column) {
            if (array_key_exists($column, $validated)) {
                $provided[$column] = is_string($validated[$column]) ? $validated[$column] : null;
            }
        }

        return $provided;
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
            $column = $role->value.'_'.$value;
            $resolved[$value] = match (true) {
                is_string($group->getAttribute($column)) => ['value' => (string) $group->getAttribute($column), 'source' => 'group'],
                is_string($group->project->getAttribute('task_'.$column)) => ['value' => (string) $group->project->getAttribute('task_'.$column), 'source' => 'project'],
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
     * @param  array<string, string|null>  $changes  keyed by group column
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
