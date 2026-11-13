<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Data\Processes\AddProcessData;
use App\Domain\Instances\ContainerProcesses;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceState;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessSpecification;
use App\Domain\Processes\ProcessTarget;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Processes\ProcessTargetType;
use App\Domain\Processes\VitePreset;
use App\Domain\Processes\VpDevPreset;
use App\Domain\ProjectDefinitions\DefinitionEnvironment;
use App\Domain\Schedules\DesiredTimerState;
use App\Domain\Schedules\ScheduleErrorCode;
use App\Domain\Schedules\ScheduleTrigger;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Process;
use App\Models\ProcessDefinition;
use App\Models\Schedule;
use App\Models\ScheduleDefinition;
use App\Support\ValidatedData;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

final readonly class InstantiateProjectRuntimeDefinitionsAction
{
    public function __construct(
        private ProcessTargetResolver $processTargets,
        private ProcessSpecification $processSpecifications,
    ) {}

    public function captureForClone(Instance $instance): Instance
    {
        return $this->capture($instance->id);
    }

    /** Record owned definitions inside the parent admission transaction. */
    public function capture(int $instanceId): Instance
    {
        return DB::transaction(function () use ($instanceId): Instance {
            $instance = Instance::query()
                ->with(['project', 'node'])
                ->lockForUpdate()
                ->findOrFail($instanceId);
            $this->assertProductionTarget($instance);
            $processTarget = $this->processTargets->forPreparation($instance);

            if ($instance->runtime_definitions_captured_at !== null) {
                return $instance;
            }

            $processDefinitions = ProcessDefinition::query()
                ->where('project_id', $instance->project_id)
                ->orderBy('name')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->filter($this->isForProduction(...));
            $scheduleDefinitions = ScheduleDefinition::query()
                ->where('project_id', $instance->project_id)
                ->orderBy('name')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->filter($this->isForProduction(...));

            foreach ($processDefinitions as $definition) {
                $this->captureProcess($instance, $definition, $processTarget);
            }

            foreach ($scheduleDefinitions as $definition) {
                $this->captureSchedule($instance, $definition);
            }

            $instance->update(['runtime_definitions_captured_at' => now()]);

            return $instance->refresh();
        });
    }

    /**
     * Record a new development Instance's Processes from the Project's development definitions, inside the
     * create admission transaction. Create installs them after its setup steps and starts each one that should run (desiredFor).
     */
    public function captureForDevelopment(Instance $instance): void
    {
        if ($instance->placedOnAppProd()) {
            return;
        }
        if ($instance->runtime_definitions_captured_at !== null) {
            if ($instance->runtime()->exists() && in_array($instance->status, [InstanceState::Reserved, InstanceState::SourceResolved], true)) {
                $this->refreshForContainer($instance, forCreateRetry: true);
            }

            return;
        }
        $definitions = $this->developmentDefinitions($instance);
        if ($definitions->isNotEmpty()) {
            $target = $this->processTargets->forPreparation($instance);
            foreach ($definitions as $definition) {
                $this->captureProcess($instance, $definition, $target, $this->desiredFor($instance, $definition));
            }
        }
        $this->initializeContainerPresetPort($instance);
        $instance->update(['runtime_definitions_captured_at' => now()]);
    }

    /**
     * Brings a container Instance's Processes up to the Project's development definitions before a deployment admits its units: a new
     * definition becomes a new record, and a changed one changes its record. The deployment installs and starts both.
     */
    public function refreshForContainer(Instance $instance, bool $forCreateRetry = false): void
    {
        $definitions = $this->developmentDefinitions($instance);
        if (! $this->servesVite($instance)) {
            // A record from before this rule goes; the deployment then stops and removes its unit as one of another owner.
            $instance->processes()->where('runtime_config->preset', VitePreset::NAME)->delete();
        }
        if ($forCreateRetry) {
            // Create owns the entire Process set; active deployments retain removed definitions.
            $instance->processes()->where(fn ($query) => $query->whereNull('source_definition_id')
                ->orWhereNotIn('source_definition_id', $definitions->modelKeys()))->delete();
        }
        if ($definitions->isEmpty()) {
            return;
        }
        $target = $this->processTargets->forPreparation($instance);
        foreach ($definitions as $definition) {
            $record = Process::query()->whereIn('owner_type', Instance::morphTypes())->where('owner_id', $instance->id)
                ->where('source_definition_id', $definition->id)->lockForUpdate()->first();
            if ($record === null) {
                $this->captureProcess($instance, $definition, $target, $this->desiredFor($instance, $definition));

                continue;
            }
            $attributes = $this->attributesForDefinition($instance, $definition, $target);
            if ($forCreateRetry) {
                $record->forceFill(['name' => $definition->name, 'desired_state' => $this->desiredFor($instance, $definition),
                    'status' => LifecycleStatus::Provisioning, 'failed_step' => null, 'error_code' => null]);
            }
            if ($record->isDirty() || ! $this->processSpecifications->matches($record, $attributes)) {
                // A running Process keeps running its installed spec until the deployment installs the new one: it stays active,
                // and its activation is pending (systemd_activation_hash still names the running unit).
                $running = ! $forCreateRetry && $record->status === LifecycleStatus::Active;
                $record->forceFill([...$attributes, ...$running ? ['systemd_activation_pending' => true] : ['status' => LifecycleStatus::Provisioning]])->save();
            }
        }
        $this->initializeContainerPresetPort($instance);
    }

    /**
     * A clone keeps a Process stopped that is stopped on purpose on its default: the default's copy of the same definition has
     * desired state stopped. Every other definition runs, also one whose copy on the default is failed or absent
     * (docs/reference/development-containers.md#processes).
     */
    private function desiredFor(Instance $instance, ProcessDefinition $definition): DesiredProcessState
    {
        $source = $instance->runtime()->value('clone_source_instance_id');
        if ($source === null) {
            return DesiredProcessState::Running;
        }
        $stopped = Process::query()->whereIn('owner_type', Instance::morphTypes())->where('owner_id', $source)
            ->where('source_definition_id', $definition->id)->where('status', '!=', LifecycleStatus::Removing)
            ->where('desired_state', DesiredProcessState::Stopped)->exists();

        return $stopped ? DesiredProcessState::Stopped : DesiredProcessState::Running;
    }

    /**
     * The Project's development definitions that apply to this Instance. A container default gets no `vite` preset: browsers reach
     * that dev server only through development ports, which a default has not, so its `public/hot` would break the default's own site.
     *
     * @return Collection<int, ProcessDefinition>
     */
    private function developmentDefinitions(Instance $instance): Collection
    {
        $vite = $this->servesVite($instance);

        return ProcessDefinition::query()->where('project_id', $instance->project_id)
            ->orderBy('name')->orderBy('id')->lockForUpdate()->get()
            ->filter(static fn (ProcessDefinition $definition): bool => in_array(DefinitionEnvironment::Development->value, $definition->environments, strict: true)
                && ($vite || ($definition->spec['preset'] ?? null) !== VitePreset::NAME));
    }

    /** False only for a container default; a checkout Instance still refuses the preset (process.container_command_unsupported). */
    private function servesVite(Instance $instance): bool
    {
        $runtime = $instance->runtime()->first();

        return ! $runtime instanceof InstanceRuntime || $instance->placedOnAppProd() || InstanceContainer::runsT3($runtime);
    }

    private function initializeContainerPresetPort(Instance $instance): void
    {
        if ($instance->vite_port === null && ! $instance->placedOnAppProd() && $instance->runtime()->exists()
            && $instance->processes()->where('runtime_config->preset', VpDevPreset::NAME)->exists()) {
            $instance->update(['vite_port' => VpDevPreset::ContainerPort]);
        }
    }

    private function assertProductionTarget(Instance $instance): void
    {
        if ($instance->placedOnAppProd()) {
            return;
        }

        throw new ResourceOperationException(
            errorCode: 'instance.runtime_definitions_target_invalid',
            message: 'Runtime definitions can be instantiated only for a production Instance.',
            status: 409,
        );
    }

    private function isForProduction(ProcessDefinition|ScheduleDefinition $definition): bool
    {
        return in_array(DefinitionEnvironment::Production->value, $definition->environments, strict: true);
    }

    private function captureProcess(
        Instance $instance,
        #[SensitiveParameter]
        ProcessDefinition $definition,
        ProcessTarget $target,
        DesiredProcessState $desired = DesiredProcessState::Stopped,
    ): void {
        $attributes = $this->attributesForDefinition($instance, $definition, $target);

        $existing = Process::query()->whereIn('owner_type', Instance::morphTypes())->where('owner_id', $instance->id)
            ->where('name', $definition->name)->lockForUpdate()->first();
        if ($existing !== null) {
            if ($target->container !== null && ! $instance->placedOnAppProd()
                && ($existing->source_definition_id === null || $existing->source_definition_id === $definition->id)
                && $existing->status !== LifecycleStatus::Removing
                && app(ContainerProcesses::class)->matchesDefinition($existing, $attributes, $instance)) {
                $existing->update(['source_definition_id' => $definition->id]);

                return;
            }
            throw new ResourceOperationException('process.name_taken', "Process [{$definition->name}] already exists with incompatible ownership or intent.", 409);
        }

        try {
            Process::query()->create([
                'owner_type' => Instance::MorphAlias,
                'owner_id' => $instance->id,
                'source_definition_id' => $definition->id,
                'name' => $definition->name,
                ...$attributes,
                'desired_state' => $desired,
                'status' => LifecycleStatus::Provisioning,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            throw new ResourceOperationException(
                errorCode: 'process.name_taken',
                message: "Process [{$definition->name}] already exists and cannot be adopted as a definition copy.",
                status: 409,
                previous: $exception,
            );
        }
    }

    private function captureSchedule(
        Instance $instance,
        #[SensitiveParameter]
        ScheduleDefinition $definition,
    ): void {
        $specification = $definition->spec;

        if (Schedule::query()
            ->whereIn('target_type', Instance::morphTypes())
            ->where('target_id', $instance->id)
            ->where('name', $definition->name)
            ->exists()) {
            throw new ResourceOperationException(
                errorCode: ScheduleErrorCode::RetryConflict->value,
                message: "Schedule [{$definition->name}] already exists and cannot be adopted as a definition copy.",
                status: 409,
            );
        }

        try {
            Schedule::query()->create([
                'target_type' => Instance::MorphAlias,
                'target_id' => $instance->id,
                'source_definition_id' => $definition->id,
                'host_node_id' => $instance->node_id,
                'name' => $definition->name,
                'calendar' => $specification['calendar'],
                'command' => $specification['command'],
                'timeout_seconds' => $specification['timeout_seconds'],
                'desired_timer_state' => DesiredTimerState::Disabled,
                // A native copy needs no Node work while disabled; enable writes its workflow and trigger.
                'trigger' => $trigger = ScheduleTrigger::forCalendar(ValidatedData::string($specification['calendar'])),
                'status' => $trigger === ScheduleTrigger::Swamp ? LifecycleStatus::Active : LifecycleStatus::Provisioning,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            throw new ResourceOperationException(
                errorCode: ScheduleErrorCode::RetryConflict->value,
                message: "Schedule [{$definition->name}] already exists and cannot be adopted as a definition copy.",
                status: 409,
                previous: $exception,
            );
        }
    }

    /** @return array{runtime: ProcessRuntime, working_directory: ?string, runtime_config: array<string,mixed>, restart_policy: string, keep_alive: bool, restart_on_deploy: bool} */
    public function attributesForDefinition(Instance $instance, ProcessDefinition $definition, ProcessTarget $target): array
    {
        $container = $target->container !== null && ! $instance->placedOnAppProd();
        if (($definition->spec['preset'] ?? null) === VitePreset::NAME && ! $container) {
            throw new ResourceOperationException('process.container_command_unsupported', "Process definition [{$definition->name}] runs only in an Instance container.", 409);
        }
        $attributes = $this->processSpecifications->attributes($this->processData($instance, $definition), $target);
        if ($container) {
            foreach (['environment_file', 'required_mount_paths', 'preset', 'preset_version'] as $key) {
                if (array_key_exists($key, $definition->spec)) {
                    $attributes['runtime_config'][$key] = $definition->spec[$key];
                }
            }
            if (($attributes['runtime_config']['preset'] ?? null) === VpDevPreset::NAME) {
                if ($definition->spec['command'] !== VpDevPreset::definitionCommand()) {
                    throw new ResourceOperationException('process.container_command_unsupported', "Process definition [{$definition->name}] has a custom preset command.", 409);
                }
                // Capture precedes port allocation. Placement renders this supported template with the destination's port.
                $attributes['runtime_config']['command'] = VpDevPreset::command();
            }
            if (($attributes['runtime_config']['preset'] ?? null) === VitePreset::NAME) {
                if ($definition->spec['command'] !== VitePreset::definitionCommand()) {
                    throw new ResourceOperationException('process.container_command_unsupported', "Process definition [{$definition->name}] has a custom preset command.", 409);
                }
                $attributes['runtime_config']['command'] = VitePreset::command($instance);
            }
        }

        return $attributes;
    }

    private function processData(
        Instance $instance,
        #[SensitiveParameter]
        ProcessDefinition $definition,
    ): AddProcessData {
        $specification = $definition->spec;
        $runtime = $specification['runtime'] ?? null;
        $command = $this->stringList($specification['command'] ?? null);
        $image = $specification['image'] ?? null;
        $workingDirectory = $specification['working_directory'] ?? null;
        $restartPolicy = $specification['restart_policy'] ?? 'never';

        if (
            ! is_string($runtime)
            || ($image !== null && ! is_string($image))
            || ($workingDirectory !== null && ! is_string($workingDirectory))
            || ! is_string($restartPolicy)
        ) {
            throw new \RuntimeException('Process definition contains invalid runtime data.');
        }

        $environment = $this->stringMap($specification['environment'] ?? []);
        $ports = $this->stringList($specification['ports'] ?? []);
        $volumes = $this->volumes($specification['volumes'] ?? []);

        return new AddProcessData(
            targetType: ProcessTargetType::Instance,
            targetId: $instance->id,
            name: $definition->name,
            runtime: ProcessRuntime::from($runtime),
            command: $command,
            image: $image,
            workingDirectory: $workingDirectory,
            environment: $environment,
            ports: $ports,
            volumes: $volumes,
            restartPolicy: $restartPolicy,
            start: false,
            keepAlive: ($specification['keep_alive'] ?? false) === true,
            restartOnDeploy: ($specification['restart_on_deploy'] ?? true) === true,
        );
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new \RuntimeException('Process definition contains invalid list data.');
        }

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new \RuntimeException('Process definition contains invalid list data.');
            }
        }

        return $value;
    }

    /** @return array<string, string> */
    private function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            throw new \RuntimeException('Process definition contains invalid map data.');
        }

        foreach ($value as $key => $item) {
            if (! is_string($key) || ! is_string($item)) {
                throw new \RuntimeException('Process definition contains invalid map data.');
            }
        }

        return $value;
    }

    /** @return list<array{source: string, target: string, read_only: bool}> */
    private function volumes(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new \RuntimeException('Process definition contains invalid volume data.');
        }

        $volumes = [];
        foreach ($value as $volume) {
            $readOnly = is_array($volume) ? ($volume['read_only'] ?? false) : null;
            if (
                ! is_array($volume)
                || ! is_string($volume['source'] ?? null)
                || ! is_string($volume['target'] ?? null)
                || ! in_array($readOnly, [true, false, 1, 0, '1', '0'], strict: true)
            ) {
                throw new \RuntimeException('Process definition contains invalid volume data.');
            }

            $volumes[] = [
                'source' => $volume['source'],
                'target' => $volume['target'],
                'read_only' => in_array($readOnly, [true, 1, '1'], strict: true),
            ];
        }

        return $volumes;
    }
}
