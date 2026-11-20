<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Instances\InstanceContainer;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AgentThread;
use App\Models\InstanceRuntime;
use App\Models\Task;
use App\Rules\NotBlank;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * The agent of each role of a task copy turn: its provider, model and effort (docs/reference/tasks.md#copy-turn-agents). The provider
 * comes from the group's `<role>_agent_provider` column, else from the Project's `task_<role>_provider` default, else from the
 * Gateway's `orbit.tasks.copy_agents`. The model and the effort come in the same order, from the first level whose value is for the
 * resolved provider (self::forProvider), else from the provider's own `orbit.tasks.copy_provider_defaults`. A request that changes a
 * level's provider alone clears that level's model and effort (self::withSwitches). The group's task VM columns `implementer_model` and
 * `reviewer_model` are never read here.
 * The provider and the effort pick the copy's T3 provider instance, which Orbit writes into the copy's T3 settings
 * (InstanceContainer::offered).
 * T3 offers its models in a catalog it fetches itself, so the turn's first step checks the model in the copy.
 */
final readonly class TaskAgent
{
    public const array Values = ['provider', 'model', 'effort'];

    /** The starts of the names and aliases of each provider's models: a model with one of them belongs to that provider. */
    public const array ModelPrefixes = ['claude' => ['claude-', 'opus', 'sonnet', 'haiku'], 'codex' => ['gpt-', 'codex-'], 'antigravity' => ['gemini-']];

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
     * A request's values keyed by field, with the model and the effort of each role whose provider it changes alone cleared: they
     * were set for the provider before. `$current` reads a field's stored value.
     *
     * @param  array<string, string|null>  $provided
     * @param  Closure(string): mixed  $current
     * @return array<string, string|null>
     */
    public static function withSwitches(array $provided, Closure $current, string $prefix = ''): array
    {
        foreach (TaskThreadRole::cases() as $role) {
            $key = $prefix.$role->value.'_';
            if (array_key_exists($key.'provider', $provided) && $provided[$key.'provider'] !== $current($key.'provider')) {
                $provided += [$key.'model' => null, $key.'effort' => null];
            }
        }

        return $provided;
    }

    /**
     * Each value of the role and where it comes from: `group`, `project`, `gateway` or `provider`.
     *
     * @return array{provider: array{value: string, source: string}, model: array{value: string, source: string}, effort: array{value: string, source: string}}
     */
    public static function resolve(Task $group, TaskThreadRole $role): array
    {
        $levels = [];
        $set = static fn (mixed $stored): ?string => is_string($stored) ? $stored : null;
        foreach (self::Values as $value) {
            $levels['group'][$value] = $set($group->getAttribute(self::groupColumn($role, $value)));
            $levels['project'][$value] = $set($group->project->getAttribute('task_'.$role->value.'_'.$value));
            $levels['gateway'][$value] = config()->string('orbit.tasks.copy_agents.'.$role->value.'.'.$value);
        }
        // Each level's own provider, else the one of the next level down that names one: the provider its values were set under.
        $levels['project']['under'] = $levels['project']['provider'] ?? $levels['gateway']['provider'];
        $levels['group']['under'] = $levels['group']['provider'] ?? $levels['project']['under'];
        $levels['gateway']['under'] = $levels['gateway']['provider'];
        $named = array_filter($levels, static fn (array $level): bool => is_string($level['provider']));
        $provider = ['value' => (string) reset($named)['provider'], 'source' => (string) array_key_first($named)];
        $resolved = ['provider' => $provider];
        foreach (['model', 'effort'] as $value) {
            $fits = array_filter($levels, static fn (array $level, string $source): bool => is_string($level[$value])
                && self::forProvider($provider['value'], $source, $level, $value), ARRAY_FILTER_USE_BOTH);
            $own = config('orbit.tasks.copy_provider_defaults.'.$provider['value'].'.'.$value);
            $resolved[$value] = match (true) {
                $fits !== [] => ['value' => (string) reset($fits)[$value], 'source' => (string) array_key_first($fits)],
                is_string($own) => ['value' => $own, 'source' => 'provider'],
                // A provider no copy offers has no default: the Gateway's value stands, and the turn's admission refuses the provider.
                default => ['value' => (string) $levels['gateway'][$value], 'source' => 'gateway'],
            };
        }

        /** @var array{provider: array{value: string, source: string}, model: array{value: string, source: string}, effort: array{value: string, source: string}} $resolved */
        return $resolved;
    }

    /**
     * Whether a level's model or effort is for `$provider`. A level that names another provider gives none. The group or the Project
     * that names the provider gives its values as set. Otherwise a model counts when it belongs to no other provider by its name
     * (self::modelProvider). An effort counts when the level's values were set under the provider, or the provider offers it, or no
     * provider does. The Gateway always names its provider, but its shipped model and effort are Claude's, so it is checked the same way.
     *
     * @param  array<string, string|null>  $level
     */
    private static function forProvider(string $provider, string $source, array $level, string $value): bool
    {
        if (is_string($level['provider']) && $level['provider'] !== $provider) {
            return false;
        }
        if ($source !== 'gateway' && is_string($level['provider'])) {
            return true;
        }
        $set = (string) $level[$value];
        if ($value === 'model') {
            return in_array(self::modelProvider($set), [null, $provider], true);
        }

        return ($source !== 'gateway' && $level['under'] === $provider) || ! array_key_exists($provider, InstanceContainer::CopyProviders)
            || in_array($set, InstanceContainer::CopyProviders[$provider], true)
            || ! in_array($set, array_merge(...array_values(InstanceContainer::CopyProviders)), true);
    }

    /** The provider a model belongs to by its name, or null for a name no prefix claims. */
    public static function modelProvider(string $model): ?string
    {
        foreach (self::ModelPrefixes as $provider => $prefixes) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($model, $prefix)) {
                    return $provider;
                }
            }
        }

        return null;
    }

    /**
     * The agent a new copy thread of the role starts with, in the copy of `$runtime`. A provider and effort the copy's T3 has no
     * instance for, such as Antigravity on a copy made before it, is refused here, before Swamp runs anything, with what the copy offers.
     *
     * @return array{provider: string, instance: string, model: string, effort: string, options: list<array{id: string, value: string}>}
     */
    public static function forNewThread(Task $group, TaskThreadRole $role, InstanceRuntime $runtime): array
    {
        $resolved = self::resolve($group, $role);
        ['provider' => ['value' => $provider], 'model' => ['value' => $model], 'effort' => ['value' => $effort]] = $resolved;
        $offered = InstanceContainer::offered($runtime);
        if (! in_array($effort, $offered[$provider] ?? [], true)) {
            throw new ResourceOperationException('tasks.copy_turn_unoffered', "The task copy's T3 offers no provider instance for {$provider} at {$effort} effort.", 422,
                details: ['role' => $role->value, 'provider' => $resolved['provider'], 'effort' => $resolved['effort'], 'offered' => $offered]);
        }
        if (preg_match(self::ModelPattern, $model) !== 1) {
            throw new ResourceOperationException('tasks.copy_turn_model_invalid', 'ORBIT_TASKS_COPY_'.strtoupper($role->value).'_MODEL must be a model name.', 503);
        }

        return ['provider' => $provider, 'instance' => InstanceContainer::providerInstance($provider, $effort), 'model' => $model, 'effort' => $effort,
            'options' => InstanceContainer::providerOptions($provider, $effort)];
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
