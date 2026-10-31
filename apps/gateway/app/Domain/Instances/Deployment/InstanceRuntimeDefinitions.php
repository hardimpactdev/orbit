<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Actions\Instances\InstantiateProjectRuntimeDefinitionsAction;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Schedule;
use App\Support\ValidatedData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** DB-only member intent, parent-owned private material and record projection for one native root. */
final readonly class InstanceRuntimeDefinitions
{
    public function __construct(
        private InstantiateProjectRuntimeDefinitionsAction $definitions,
        private ProcessTargetResolver $targets,
        private SystemdProcessRenderer $systemd,
        private DockerProcessRenderer $docker,
    ) {}

    /** Called inside root admission before its immutable input is stored.
     * @return array{processes:list<array<string,mixed>>,schedules:list<array<string,mixed>>,restart:list<array<string,mixed>>}
     */
    public function capture(Instance $instance): array
    {
        return DB::transaction(function () use ($instance): array {
            $instance = $this->definitions->capture($instance->id);
            $processes = $this->processes($instance)->whereNotNull('source_definition_id')
                ->whereNotIn('status', [LifecycleStatus::Active, LifecycleStatus::Removing])->orderBy('id')->lockForUpdate()->get();
            $schedules = $this->schedules($instance)->whereNotNull('source_definition_id')
                ->whereNotIn('status', [LifecycleStatus::Active, LifecycleStatus::Removing])->orderBy('id')->lockForUpdate()->get();

            return ['processes' => array_values($processes->map(fn (Process $row): array => $this->processInput($row, true))->all()),
                'schedules' => array_values($schedules->map(fn (Schedule $row): array => $this->scheduleInput($instance, $row))->all()),
                'restart' => $this->restartSnapshot($instance)['restart']];
        });
    }

    /** Rollback restarts current owned Processes; it never captures or installs new definitions.
     * @return array{processes:list<array<string,mixed>>,schedules:list<array<string,mixed>>,restart:list<array<string,mixed>>}
     */
    public function restartSnapshot(Instance $instance): array
    {
        if (! $instance->placedOnAppProd()) {
            throw $this->refused();
        }
        $this->targets->forPreparation($instance);
        $processes = $this->processes($instance)->where('status', LifecycleStatus::Active)
            ->where('desired_state', DesiredProcessState::Running)->where('restart_on_deploy', true)->orderBy('id')->lockForUpdate()->get();

        return ['processes' => [], 'schedules' => [],
            'restart' => array_values($processes->map(fn (Process $row): array => $this->processInput($row, false))->all())];
    }

    /** Existing instance.deployment private material; no leaf Operation or new secret source.
     * @return array{processes:array<int,array<string,mixed>>,schedules:array<string,array<string,mixed>>}
     */
    public function material(Operation $operation): array
    {
        $instance = $this->parent($operation);
        if ($operation->status->terminal()) {
            throw $this->refused();
        }
        $runtime = $this->admitted($operation);
        $processes = $schedules = [];
        foreach ($runtime['processes'] as $entry) {
            $id = ValidatedData::integer($entry['process_id']);
            $row = $this->processes($instance)->whereKey($id)->whereNotNull('source_definition_id')->first();
            if ($row === null || Arr::sortRecursive($this->processInput($row, true)) !== Arr::sortRecursive($entry)) {
                throw $this->refused();
            }
            $target = $this->targets->forInstallation($row);
            $processes[$id] = ['installation' => $row->runtime === ProcessRuntime::Systemd
                ? ['runtime' => 'systemd', 'content' => $this->systemd->render($row, $target), 'environment' => $this->systemd->environmentContent($row, $target)]
                : ['runtime' => 'docker', 'spec' => $entry['spec'], 'definition' => $this->docker->containerInput($row, $target)]];
        }
        foreach ($runtime['schedules'] as $entry) {
            $id = ValidatedData::string($entry['id']);
            $row = $this->schedules($instance)->whereKey($id)->whereNotNull('source_definition_id')->first();
            if ($row === null || Arr::sortRecursive($this->scheduleInput($instance, $row)) !== Arr::sortRecursive($entry)) {
                throw $this->refused();
            }
            $schedules[$id] = ['command' => $row->command];
        }

        return ['processes' => $processes, 'schedules' => $schedules];
    }

    /** Record bounded native member outcomes. The Gateway does not revalidate runtime work.
     * @param  array<string,mixed>  $result
     */
    public function project(Operation $operation, array $result): void
    {
        DB::transaction(function () use ($operation, $result): void {
            $instance = $this->parent($operation);
            $admitted = $this->admitted($operation);
            foreach (['processes', 'restart', 'schedules'] as $family) {
                $key = $family === 'schedules' ? 'id' : 'process_id';
                $entries = array_column($admitted[$family], null, $key);
                $outcomes = $result[$family] ?? [];
                if (! is_array($outcomes) || ! array_is_list($outcomes)) {
                    throw $this->refused();
                }
                foreach ($outcomes as $raw) {
                    $outcome = ValidatedData::object($raw);
                    $id = $family === 'schedules' ? ValidatedData::string($outcome[$key]) : ValidatedData::integer($outcome[$key]);
                    $entry = $entries[$id] ?? null;
                    if ($entry === null) {
                        throw $this->refused();
                    }
                    $row = ($family === 'schedules' ? $this->schedules($instance) : $this->processes($instance))
                        ->whereKey($id)->lockForUpdate()->first();
                    if ($row === null) {
                        throw $this->refused();
                    }
                    $ready = ($outcome['ready'] ?? false) === true;
                    $attributes = ['status' => $ready ? LifecycleStatus::Active : LifecycleStatus::Failed,
                        'failed_step' => $ready ? null : $family.'.'.$id,
                        'error_code' => $ready ? null : ValidatedData::string($outcome['error_code'])];
                    if ($row instanceof Process) {
                        if ($ready && $family === 'processes') {
                            $attributes += ['retired_runtime' => null, ...isset($entry['content_hash'])
                                ? ['systemd_activation_hash' => $entry['content_hash'], 'systemd_activation_pending' => false] : []];
                        }
                        $row->forceFill($attributes)->save();
                    } else {
                        $row->update($attributes);
                    }
                }
            }
        });
    }

    /** @return array<string,mixed> */
    private function processInput(Process $row, bool $installation): array
    {
        $target = $this->targets->forInstallation($row);
        $systemd = $row->runtime === ProcessRuntime::Systemd;
        $safe = ['process_id' => $row->id, 'runtime' => $row->runtime->value,
            'name' => $systemd ? $this->systemd->unitName($row) : $this->docker->containerName($row),
            'on_demand' => $target->onDemandHostStart];
        if ($target->productionReleaseLayout && ($row->desired_state === DesiredProcessState::Running || ! $installation)) {
            $safe['directory'] = $target->defaultWorkingDirectory;
        }
        if (! $installation) {
            return [...$safe, 'vite' => null];
        }
        $retired = null;
        if ($row->retired_runtime !== null) {
            $prior = clone $row;
            $prior->runtime = $row->retired_runtime;
            $retired = ['runtime' => $prior->runtime->value, 'name' => $prior->runtime === ProcessRuntime::Systemd
                ? $this->systemd->unitName($prior) : $this->docker->containerName($prior)];
        }

        return [...$safe, 'desired' => $row->desired_state->value, 'retired' => $retired,
            ...($systemd ? ['content_hash' => hash('sha256', $this->systemd->render($row, $target)),
                'activation_pending' => $row->systemd_activation_pending] : ['spec' => $this->docker->specHash($row, $target)])];
    }

    /** Actual account group remains worker-local, never guessed during Gateway admission.
     * @return array<string,mixed>
     */
    private function scheduleInput(Instance $instance, Schedule $row): array
    {
        $target = $this->targets->forPreparation($instance);

        return ['id' => $row->id, 'command_hash' => hash('sha256', $row->command),
            'user' => $target->user, 'home' => $instance->production_home,
            'working_directory' => $target->defaultWorkingDirectory, 'shell' => '/bin/bash', 'login_shell' => false,
            'calendar' => $row->calendar, 'timeout_seconds' => $row->timeout_seconds, 'desired' => $row->desired_timer_state->value];
    }

    private function parent(Operation $operation): Instance
    {
        $instance = Instance::query()->with('node')->find($operation->target_id);
        if (! in_array($operation->kind, ['instance.deploy', 'instance.rollback'], true)
            || $operation->target_type !== 'instance' || $instance === null || $instance->node_id !== $operation->node_id
            || ($operation->input['instance_id'] ?? null) !== $instance->id || ! $instance->placedOnAppProd()) {
            throw $this->refused();
        }

        return $instance;
    }

    /** @return array{processes:list<array<string,mixed>>,schedules:list<array<string,mixed>>,restart:list<array<string,mixed>>} */
    private function admitted(Operation $operation): array
    {
        $value = ValidatedData::object($operation->input['runtime_definitions'] ?? []);
        foreach (['processes', 'schedules', 'restart'] as $key) {
            if (! isset($value[$key]) || ! is_array($value[$key]) || ! array_is_list($value[$key])) {
                throw $this->refused();
            }
        }

        return $value;
    }

    /** @return Builder<Process> */
    private function processes(Instance $instance): Builder
    {
        return Process::query()->whereIn('owner_type', Instance::morphTypes())->where('owner_id', $instance->id);
    }

    /** @return Builder<Schedule> */
    private function schedules(Instance $instance): Builder
    {
        return Schedule::query()->whereIn('target_type', Instance::morphTypes())->where('target_id', $instance->id)
            ->where('host_node_id', $instance->node_id);
    }

    private function refused(): ResourceOperationException
    {
        return new ResourceOperationException('operation.execution_forbidden', 'The runtime member does not belong to this admitted Instance deployment.', 409);
    }
}
