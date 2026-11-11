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
use PDOException;
use Ramsey\Uuid\Uuid;
use Throwable;

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
            // SQL filters and raw identities do not invoke stored model casts.
            $ids = Instance::query()->where('name', 'default')->where('status', 'active')
                ->whereHas('runtime', static fn ($query) => $query->where('power_state', 'running'))
                ->whereHas('node', static fn ($query) => $query->where('status', 'active')
                    ->whereHas('roles', static fn ($query) => $query->where('role', 'app-dev')->where('status', 'active')))
                ->whereDoesntHave('node.roles', static fn ($query) => $query->where('role', 'app-prod'))
                ->whereHas('processes', static fn ($query) => $query->whereNull('source_definition_id')->where('status', '!=', 'removing'))
                ->orderBy('id')->lockForUpdate()->toBase()->pluck('id');
            foreach ($ids as $id) {
                $instanceId = ValidatedData::integer($id);
                $rows = DB::table('processes')->whereIn('owner_type', Instance::morphTypes())->where('owner_id', $instanceId)
                    ->whereNull('source_definition_id')->where('status', '!=', 'removing')->orderBy('id')->lockForUpdate()->get(['id', 'name']);
                try {
                    $instance = Instance::query()->findOrFail($instanceId);
                    $admitted = $this->historicalUnits($instance);
                    if ($admitted === null) {
                        continue;
                    }
                } catch (Throwable $exception) {
                    foreach ($rows as $row) {
                        $this->recordSkip($instanceId, (int) $row->id, (string) $row->name, $exception);
                    }

                    continue;
                }
                foreach ($rows as $row) {
                    try {
                        DB::transaction(function () use ($instance, $row, $admitted): void {
                            $process = Process::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
                            $unit = collect($admitted)->firstWhere('id', $process->id);
                            if (! is_array($unit) || ! is_string($unit['content'] ?? null) || $process->status !== LifecycleStatus::Active
                                || $process->systemd_activation_hash !== hash('sha256', $unit['content'])) {
                                throw new ResourceOperationException('instance.migrate_process_definition_unproven',
                                    "Process [{$process->id}:{$process->name}] is not proven by the migrated default's activation.", 409);
                            }
                            if ($this->processes->unit($instance, $process, migrate: true)['content'] !== $unit['content']) {
                                throw new ResourceOperationException('instance.migrate_process_definition_unproven',
                                    "Process [{$process->id}:{$process->name}] current unit content differs from the admitted unit.", 409);
                            }
                            $this->capture($instance, [$process->id], historical: true);
                        });
                    } catch (Throwable $exception) {
                        $this->recordSkip($instanceId, (int) $row->id, (string) $row->name, $exception);
                    }
                }
            }
        });
    }

    /** @return array<array-key, mixed>|null Fresh container defaults need no historical repair. */
    private function historicalUnits(Instance $instance): ?array
    {
        if ($instance->placedOnAppProd()) {
            return null;
        }
        $runtime = $instance->runtime()->lockForUpdate()->first();
        $operation = Operation::query()->where('kind', 'instance.migrate')->where('target_type', 'instance')->where('target_id', $instance->id)
            ->latest('id')->first();
        if ($operation === null) {
            return null;
        }
        if ($runtime !== null && $runtime->seed_commit === null) {
            throw new ResourceOperationException('instance.migrate_process_definition_unproven',
                "Instance [{$instance->id}:{$instance->name}] runtime has no seed commit to prove its migrated Processes.", 409);
        }
        $result = $operation->result ?? [];
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

        return is_array($admitted) ? $admitted : [];
    }

    private function recordSkip(int $instanceId, int $processId, string $name, Throwable $exception): void
    {
        // Constraint and value errors are row data failures; connection/storage failures must still surface.
        if ($exception instanceof PDOException
            && ! in_array(substr((string) $exception->getCode(), 0, 2), ['22', '23'], true)
            && ! (DB::connection()->getDriverName() === 'sqlite' && in_array($exception->errorInfo[1] ?? null, [18, 19, 20], true))) {
            throw $exception;
        }
        $reason = $exception instanceof ResourceOperationException
            ? $exception->getMessage() : $exception::class.': Stored data could not be proved.';
        Activity::query()->updateOrCreate([
            'request_id' => (string) Uuid::uuid5(Uuid::NAMESPACE_URL, "orbit:2027_02_06_capture_migrated_process_definitions:{$instanceId}:{$processId}"),
        ], [
            'log_name' => 'instances', 'description' => 'instance.migrate_process_definition_skipped',
            'subject_type' => Instance::MorphAlias, 'subject_id' => $instanceId,
            'command' => 'instance:migrate:repair', 'status' => 'succeeded',
            'properties' => ['instance_id' => $instanceId, 'process_id' => $processId,
                'process_name' => mb_convert_encoding($name, 'UTF-8', 'UTF-8'),
                'reason' => mb_strcut(mb_convert_encoding($reason, 'UTF-8', 'UTF-8'), 0, 512, 'UTF-8')],
        ]);
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
