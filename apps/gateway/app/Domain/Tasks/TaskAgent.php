<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Instances\ContainerServer;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AgentThread;
use App\Models\Task;
use App\Rules\NotBlank;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * The agent of each role of a task copy turn: its provider, model and effort (docs/reference/tasks.md#copy-turn-agents). The provider
 * comes from the group's `<role>_agent_provider` column, else from the Project's `task_<role>_provider` default, else from the
 * Gateway's `orbit.tasks.copy_agents`. The model and the effort come from the group's column, else from the Project's default or the
 * Gateway's setting only where that level's provider is the resolved one, else from the provider's own
 * `orbit.tasks.copy_provider_defaults`. The group's task VM columns `implementer_model` and `reviewer_model` are never read here.
 * The provider and the effort pick the copy's T3 provider instance, which Orbit writes into the copy's T3 settings
 * (InstanceContainer::copyProviders).
 * T3 offers its models in a catalog it fetches itself, so the turn's first step checks the model in the copy.
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
     * Each value of the role and where it comes from: `group`, `project`, `gateway` or `provider`. A model or effort of the Project or
     * the Gateway counts only where that level's provider, its own or the Gateway's, is the resolved provider; else the provider's own
     * default applies. A provider with no default of its own, which no copy offers, keeps the Gateway's value.
     *
     * @return array{provider: array{value: string, source: string}, model: array{value: string, source: string}, effort: array{value: string, source: string}}
     */
    public static function resolve(Task $group, TaskThreadRole $role): array
    {
        $gateway = static fn (string $value): string => config()->string('orbit.tasks.copy_agents.'.$role->value.'.'.$value);
        $projected = static fn (string $value): mixed => $group->project->getAttribute('task_'.$role->value.'_'.$value);
        $grouped = $group->getAttribute(self::groupColumn($role, 'provider'));
        $projectProvider = is_string($projected('provider')) ? $projected('provider') : $gateway('provider');
        $provider = match (true) {
            is_string($grouped) => ['value' => $grouped, 'source' => 'group'],
            is_string($projected('provider')) => ['value' => $projected('provider'), 'source' => 'project'],
            default => ['value' => $gateway('provider'), 'source' => 'gateway'],
        };
        $resolved = ['provider' => $provider];
        foreach (['model', 'effort'] as $value) {
            $grouped = $group->getAttribute(self::groupColumn($role, $value));
            $own = config('orbit.tasks.copy_provider_defaults.'.$provider['value'].'.'.$value);
            $resolved[$value] = match (true) {
                is_string($grouped) => ['value' => $grouped, 'source' => 'group'],
                is_string($projected($value)) && $projectProvider === $provider['value'] => ['value' => $projected($value), 'source' => 'project'],
                $gateway('provider') === $provider['value'] || ! is_string($own) => ['value' => $gateway($value), 'source' => 'gateway'],
                default => ['value' => $own, 'source' => 'provider'],
            };
        }

        /** @var array{provider: array{value: string, source: string}, model: array{value: string, source: string}, effort: array{value: string, source: string}} $resolved */
        return $resolved;
    }

    /**
     * The agent a new copy thread of the role starts with, in a copy whose recorded server is `$server`. A provider and effort the
     * copy's T3 has no instance for, such as Antigravity on a copy without the Conn Server, is refused here, before Swamp runs
     * anything, with what the copy offers.
     *
     * @return array{provider: string, instance: string, model: string, effort: string, options: list<array{id: string, value: string}>}
     */
    public static function forNewThread(Task $group, TaskThreadRole $role, ContainerServer $server): array
    {
        $resolved = self::resolve($group, $role);
        ['provider' => ['value' => $provider], 'model' => ['value' => $model], 'effort' => ['value' => $effort]] = $resolved;
        $offered = InstanceContainer::copyProviders($server);
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
