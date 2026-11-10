<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessTarget;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Models\Instance;
use App\Models\Process;
use App\Support\ValidatedData;
use Illuminate\Database\Eloquent\Collection;

/**
 * The Processes of an Instance container (docs/reference/development-containers.md#processes): `orbit-*` units in the container, from
 * the Project's development definitions. The units are public intent; their environment values reach the container only through one
 * vault copy, a JSON object of environment file path to content, that the run makes from the same records.
 */
final readonly class ContainerProcesses
{
    public function __construct(private ProcessTargetResolver $targets, private SystemdProcessRenderer $systemd) {}

    /**
     * The `processes` of the container intent, in id order. `environment` names the unit's own environment file, or null when its
     * definition sets no value.
     *
     * @return list<array{id:int,name:string,content:string,environment:?string,desired:string}>
     */
    public function units(Instance $instance): array
    {
        $units = [];
        foreach ($this->records($instance) as $process) {
            $process = $this->placed($process, $instance);
            $target = $this->targets->forInspection($process);
            if ($target->container === null || $process->runtime !== ProcessRuntime::Systemd) {
                throw new ResourceOperationException('process.target_unsupported', 'A Process in an Instance container must be an ordinary systemd Process.', 409);
            }
            $units[] = ['id' => $process->id, 'name' => $this->systemd->unitName($process), 'content' => $this->systemd->render($process, $target),
                'environment' => $this->hasEnvironment($process, $target) ? $this->systemd->environmentPath($process, $target) : null,
                'desired' => $process->desired_state->value];
        }

        return $units;
    }

    /**
     * The vault copy's content: each admitted environment file and its values, but only while the records still render the admitted
     * units, so a value changed after admission never lands under an older unit.
     */
    public function environments(Instance $instance, mixed $admitted): string
    {
        if ($admitted !== $this->units($instance)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The container Processes changed after admission.', 403);
        }
        $files = [];
        foreach ($this->records($instance) as $process) {
            $process = $this->placed($process, $instance);
            $target = $this->targets->forInspection($process);
            if ($this->hasEnvironment($process, $target)) {
                $files[$this->systemd->environmentPath($process, $target)] = $this->systemd->environmentContent($process, $target);
            }
        }

        return json_encode((object) $files, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Records the run's outcome on the admitted Processes: a passed run installed and started every one; a failed run leaves the
     * Processes it had not installed failed and the running ones as they were.
     */
    public function complete(Instance $instance, mixed $admitted, bool $succeeded, ?string $errorCode): void
    {
        foreach (is_array($admitted) ? $admitted : [] as $raw) {
            $unit = ValidatedData::object($raw);
            $process = Process::query()->whereIn('owner_type', Instance::morphTypes())->where('owner_id', $instance->id)
                ->whereKey(ValidatedData::integer($unit['id']))->lockForUpdate()->first();
            if ($process === null) {
                continue;
            }
            if ($succeeded) {
                $placed = $this->placed($process, $instance);
                $process->forceFill(['working_directory' => $placed->working_directory, 'runtime_config' => $placed->runtime_config,
                    'status' => LifecycleStatus::Active, 'failed_step' => null, 'error_code' => null,
                    'systemd_activation_hash' => hash('sha256', ValidatedData::string($unit['content'])), 'systemd_activation_pending' => false])->save();
            } elseif ($process->status !== LifecycleStatus::Active) {
                $process->forceFill(['status' => LifecycleStatus::Failed, 'failed_step' => 'container', 'error_code' => $errorCode ?? 'operation.cancelled'])->save();
            }
        }
    }

    /** Render a copy at its container placement; migration saves these paths only after cutover succeeds. */
    private function placed(Process $process, Instance $instance): Process
    {
        $placed = clone $process;
        $checkout = rtrim($instance->checkout_path, '/');
        $config = $process->runtime_config;
        array_walk_recursive($config, static function (mixed &$value) use ($checkout): void {
            if (is_string($value)) {
                $value = str_replace($checkout, InstanceHostIntent::Workspace, $value);
            }
        });
        $placed->runtime_config = $config;
        if (is_string($process->working_directory)) {
            $placed->working_directory = str_replace($checkout, InstanceHostIntent::Workspace, $process->working_directory);
        }

        return $placed;
    }

    /** The host units that migration stops, restores on failure and retires after cutover.
     * @return list<array{name:string,path:string,environment_directory:string,desired:string}>
     */
    public function source(Instance $instance): array
    {
        $units = [];
        foreach ($this->records($instance) as $process) {
            $target = $this->targets->forInspection($process);
            $units[] = ['name' => $this->systemd->unitName($process), 'path' => $this->systemd->unitPath($process),
                'environment_directory' => dirname($this->systemd->environmentPath($process, $target)),
                'desired' => $process->desired_state->value];
        }

        return $units;
    }

    /** @return Collection<int, Process> */
    private function records(Instance $instance): Collection
    {
        return Process::query()->whereIn('owner_type', Instance::morphTypes())->where('owner_id', $instance->id)
            ->where('status', '!=', LifecycleStatus::Removing)->orderBy('id')->get();
    }

    private function hasEnvironment(Process $process, ProcessTarget $target): bool
    {
        return $this->systemd->environmentContent($process, $target) !== '# Orbit Process '.$process->id."\n";
    }
}
