<?php

declare(strict_types=1);

namespace App\Domain\InstanceMigration;

use App\Actions\Instances\InstantiateProjectRuntimeDefinitionsAction;
use App\Domain\Instances\ContainerProcessCommands;
use App\Domain\Instances\ContainerProcesses;
use App\Domain\Instances\InstanceHostIntent;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Processes\VpDevPreset;
use App\Domain\ProjectDefinitions\DefinitionEnvironment;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Activity;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Process;
use App\Models\ProcessDefinition;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use TypeError;
use ValueError;

/** Data-only capture, inside the transaction that records an authoritative container cutover. */
final readonly class MigratedProcessDefinitions
{
    public function __construct(private ContainerProcesses $processes, private ContainerProcessCommands $commands) {}

    /** @param list<int> $ids */
    public function capture(Instance $instance, array $ids, bool $historical = false): void
    {
        $records = Process::query()->whereIn('owner_type', Instance::morphTypes())->where('owner_id', $instance->id)
            ->whereIn('id', $ids)->whereNull('source_definition_id')->orderBy('id')->lockForUpdate()->get();
        foreach ($records as $process) {
            $spec = $this->specification($process, $instance);
            $command = ValidatedData::stringList($spec['command']);
            $index = $this->commands->commandIndex($command);
            if ($historical && ($index === null || ! $this->commands->supportsName($command[$index]))) {
                throw new ResourceOperationException('instance.migrate_process_definition_unproven',
                    "Process [{$process->id}:{$process->name}] has no provable runtime command for historical capture.", 409);
            }
            $definition = ProcessDefinition::query()->where('project_id', $instance->project_id)->where('name', $process->name)->lockForUpdate()->first();
            if ($definition === null) {
                $definition = ProcessDefinition::query()->create(['project_id' => $instance->project_id, 'name' => $process->name,
                    'environments' => [DefinitionEnvironment::Development->value], 'spec' => $spec]);
            } else {
                $this->assertCompatible($process, $instance, $definition);
            }
            $process->update(['source_definition_id' => $definition->id]);
        }
    }

    /** Read-only, after runtime reservation inside admission. */
    public function assertCanCapture(Instance $instance): void
    {
        foreach ($instance->processes()->whereNull('source_definition_id')->orderBy('id')->get() as $process) {
            $definition = ProcessDefinition::query()->where('project_id', $instance->project_id)->where('name', $process->name)->lockForUpdate()->first();
            if ($definition !== null) {
                $this->assertCompatible($process, $instance, $definition);
            }
        }
    }

    private function assertCompatible(Process $process, Instance $instance, ProcessDefinition $definition): void
    {
        $target = app(ProcessTargetResolver::class)->forInspection($process);
        if ($definition->environments !== [DefinitionEnvironment::Development->value]
            || ! $this->processes->matchesDefinition($process,
                app(InstantiateProjectRuntimeDefinitionsAction::class)->attributesForDefinition($instance, $definition, $target), $instance)) {
            throw new ResourceOperationException('process_definition.name_taken',
                "Process [{$process->id}:{$process->name}] conflicts with Project [{$instance->project_id}] definition [{$definition->name}].", 409);
        }
    }

    /** Capture each proven historical Process; record unproven rows without blocking the release. */
    public function repair(): void
    {
        DB::transaction(function (): void {
            $instances = Instance::query()->where('name', 'default')->where('status', 'active')
                ->whereHas('runtime', static fn ($query) => $query->where('power_state', 'running'))
                ->whereHas('node', static fn ($query) => $query->where('status', 'active')
                    ->whereHas('roles', static fn ($query) => $query->where('role', 'app-dev')->where('status', 'active')))
                ->whereDoesntHave('node.roles', static fn ($query) => $query->where('role', 'app-prod'))
                ->whereHas('processes', static fn ($query) => $query->whereNull('source_definition_id')->where('status', '!=', 'removing'))
                ->orderBy('id')->lockForUpdate()->get();
            foreach ($instances as $instance) {
                if ($instance->placedOnAppProd()) {
                    continue;
                }
                $runtime = $instance->runtime()->lockForUpdate()->first();
                $operation = Operation::query()->where('kind', 'instance.migrate')->where('target_type', 'instance')->where('target_id', $instance->id)
                    ->latest('id')->first();
                // Fresh container defaults were never migrated and need no repair.
                if ($operation === null) {
                    continue;
                }
                $result = $operation->result ?? [];
                $records = $instance->processes()->whereNull('source_definition_id')->where('status', '!=', LifecycleStatus::Removing->value)->lockForUpdate()->get();
                foreach ($records as $process) {
                    try {
                        if ($runtime !== null && $runtime->seed_commit === null) {
                            throw new ResourceOperationException('instance.migrate_process_definition_unproven',
                                "Instance [{$instance->id}:{$instance->name}] runtime has no seed commit to prove its migrated Processes.", 409);
                        }
                        $settled = $runtime !== null && $operation->status->terminal()
                            && ($result['moved'] ?? null) === true && ($result['restored'] ?? false) !== true
                            && (($result['ready'] ?? false) === true || ($result['failed_step'] ?? null) === 'retire')
                            && ($result['instance_id'] ?? null) === $instance->id && ($result['commit'] ?? null) === $runtime->seed_commit
                            && ($runtime->operation_id === null || $runtime->operation_id === $operation->id)
                            && ! Operation::query()->where('target_type', 'instance')->where('target_id', $instance->id)
                                ->whereIn('status', ['queued', 'waiting', 'running'])->exists();
                        if (! $settled) {
                            throw new ResourceOperationException('instance.migrate_process_definition_unproven',
                                "Instance [{$instance->id}:{$instance->name}] has no proven settled migration for its direct Processes.", 409);
                        }
                        $admitted = ValidatedData::object($operation->input['container'] ?? [])['processes'] ?? [];
                        $unit = collect(is_array($admitted) ? $admitted : [])->firstWhere('id', $process->id);
                        if (! is_array($unit) || ! is_string($unit['content'] ?? null) || $process->status !== LifecycleStatus::Active
                            || $process->systemd_activation_hash !== hash('sha256', $unit['content'])
                            || $this->processes->unit($instance, $process, migrate: true)['content'] !== $unit['content']) {
                            throw new ResourceOperationException('instance.migrate_process_definition_unproven',
                                "Process [{$process->id}:{$process->name}] is not proven by the migrated default's activation.", 409);
                        }
                        $this->capture($instance, [$process->id], historical: true);
                    } catch (ResourceOperationException|InvalidArgumentException|TypeError|ValueError $exception) {
                        Activity::query()->create(['log_name' => 'instances', 'description' => 'instance.migrate_process_definition_skipped',
                            'subject_type' => Instance::MorphAlias, 'subject_id' => $instance->id,
                            'command' => 'instance:migrate:repair', 'request_id' => (string) Str::uuid(), 'status' => 'succeeded',
                            'properties' => ['instance_id' => $instance->id, 'process_id' => $process->id, 'process_name' => $process->name,
                                'reason' => $exception->getMessage()]]);
                    }
                }
            }
        });
    }

    /** @return array<string,mixed> */
    private function specification(Process $process, Instance $instance): array
    {
        $placed = $this->processes->forDefinition($process, $instance);
        $config = $placed->runtime_config;
        $command = $this->commands->names(ValidatedData::stringList($process->isVpDev() ? $config['command'] : $process->runtime_config['command']),
            $instance->project->php_version, "Process [{$process->id}:{$process->name}]");
        array_walk_recursive($command, static function (mixed &$value) use ($instance): void {
            if (is_string($value)) {
                $value = str_replace(rtrim($instance->checkout_path, '/'), InstanceHostIntent::Workspace, $value);
            }
        });
        if ($process->isVpDev()) {
            $command = VpDevPreset::definitionCommand();
        }

        return ['runtime' => $process->runtime->value, 'command' => $command, 'working_directory' => $placed->working_directory,
            'restart_policy' => $process->restart_policy, 'keep_alive' => $process->keep_alive, 'restart_on_deploy' => $process->restart_on_deploy,
            ...array_intersect_key($config, array_flip(['environment', 'environment_file', 'required_mount_paths', 'preset', 'preset_version']))];
    }
}
