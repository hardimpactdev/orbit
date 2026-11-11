<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessTarget;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Processes\VpDevPreset;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Models\Instance;
use App\Models\Process;
use App\Support\ValidatedData;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

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
        return $this->render($instance, $this->records([$instance->id]));
    }

    /**
     * @param  Collection<int, Process>  $records
     * @return list<array{id:int,name:string,content:string,environment:?string,desired:string}>
     */
    private function render(Instance $instance, Collection $records): array
    {
        $port = $this->vitePort($instance, $records);
        $units = [];
        foreach ($records as $process) {
            $process = $this->placed($process, $instance, $port);
            $target = $this->targets->forInspection($process);
            if ($target->container === null || $process->runtime !== ProcessRuntime::Systemd) {
                throw new ResourceOperationException('process.target_unsupported', "Process [{$process->id}:{$process->name}] must use systemd in an Instance container.", 409);
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
        $records = $this->records([$instance->id]);
        if ($admitted !== $this->render($instance, $records)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The container Processes changed after admission.', 403);
        }
        $port = $this->vitePort($instance, $records);
        $files = [];
        foreach ($records as $process) {
            $process = $this->placed($process, $instance, $port);
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
        $port = $succeeded ? $this->vitePort($instance) : null;
        foreach (is_array($admitted) ? $admitted : [] as $raw) {
            $unit = ValidatedData::object($raw);
            $process = Process::query()->whereIn('owner_type', Instance::morphTypes())->where('owner_id', $instance->id)
                ->whereKey(ValidatedData::integer($unit['id']))->lockForUpdate()->first();
            if ($process === null) {
                continue;
            }
            if ($succeeded) {
                $placed = $this->placed($process, $instance, $port);
                $process->forceFill(['working_directory' => $placed->working_directory, 'runtime_config' => $placed->runtime_config,
                    'status' => LifecycleStatus::Active, 'failed_step' => null, 'error_code' => null,
                    'systemd_activation_hash' => hash('sha256', ValidatedData::string($unit['content'])), 'systemd_activation_pending' => false])->save();
            } elseif ($process->status !== LifecycleStatus::Active) {
                $process->forceFill(['status' => LifecycleStatus::Failed, 'failed_step' => 'container', 'error_code' => $errorCode ?? 'operation.cancelled'])->save();
            }
        }
    }

    /** Render a copy at its container placement; migration saves these paths only after cutover succeeds. */
    private function placed(Process $process, Instance $instance, ?int $port): Process
    {
        $placed = clone $process;
        $checkout = rtrim($instance->checkout_path, '/');
        $config = $process->runtime_config;
        if (array_key_exists('preset', $config)) {
            $this->assertPreset($process, $instance, $port);
            $config['command'] = VpDevPreset::command($port);
            $config['environment_file'] ??= InstanceContainer::application($instance).'/.env';
        }
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

    /**
     * The preset's port in this container's network namespace; no host listener or host port allocator is involved.
     *
     * @param  Collection<int, Process>|null  $records
     */
    public function vitePort(Instance $instance, ?Collection $records = null): ?int
    {
        $records = ($records ?? $this->records([$instance->id]))->filter(static fn (Process $process): bool => $process->status !== LifecycleStatus::Removing);
        $presets = $records->filter(static fn (Process $process): bool => $process->isVpDev())->values();
        $preset = $presets->first();
        if ($preset === null) {
            return null;
        }
        $port = $instance->vite_port;
        if (! is_int($port) || $port < InstanceDevPorts::First || $port > InstanceDevPorts::Last
            || in_array($port, [InstanceContainer::WebPort, InstanceHostIntent::T3Port, $instance->ssr_port], true)) {
            $this->refuse($preset, 'port_conflict', 'its assigned Vite port is missing, invalid or reserved by a container service');
        }
        if ($presets->count() > 1) {
            $this->refuse($preset, 'port_conflict', 'another vp-dev Process owns the same assigned port');
        }
        foreach ($records as $other) {
            if ($other->id === $preset->id) {
                continue;
            }
            $config = $other->runtime_config;
            $environment = is_array($config['environment'] ?? null) ? $config['environment'] : [];
            $command = is_array($config['command'] ?? null) ? $config['command'] : [];
            $arguments = array_values(array_filter($command, is_string(...)));
            $usesPort = in_array((string) $port, array_intersect_key($environment, array_flip(['PORT', 'ORBIT_DEV_SERVER_PORT'])), true)
                || in_array('--port='.$port, $arguments, true);
            foreach ($arguments as $index => $argument) {
                $usesPort = $usesPort || ($argument === '--port' && ($arguments[$index + 1] ?? null) === (string) $port);
            }
            if ($usesPort) {
                $this->refuse($preset, 'port_conflict', "its assigned Vite port conflicts with Process [{$other->id}:{$other->name}]");
            }
        }

        return $port;
    }

    /** A preset is mapped only after its stored intent matches a supported form; never discard a custom command. */
    private function assertPreset(Process $process, Instance $instance, ?int $port): void
    {
        $config = $process->runtime_config;
        if (! $process->isVpDev() || (array_key_exists('preset_version', $config) && $config['preset_version'] !== 1)) {
            $this->refuse($process, 'preset_unsupported', 'its preset or preset version is unsupported');
        }
        $unknown = array_diff(array_keys($config), ['preset', 'preset_version', 'command', 'environment_file', 'environment', 'restart_policy']);
        if ($unknown !== []) {
            $this->refuse($process, 'preset_unsupported', 'its preset has unsupported options: '.implode(', ', $unknown));
        }
        $surveyed = ['/usr/bin/env', '-u', 'VITE_DEV_SERVER_CERT', '-u', 'VITE_DEV_SERVER_KEY',
            '/home/'.$instance->node->user.'/.vite-plus/bin/vp', 'dev', '--host=127.0.0.1', '--port='.$port, '--strictPort'];
        if (! in_array($config['command'] ?? null, [VpDevPreset::command(), VpDevPreset::command($port), $surveyed], true)) {
            $this->refuse($process, 'command_unsupported', 'its preset has a custom command');
        }
        $this->assertWorkspacePath($process, $instance, $process->working_directory, 'working directory');
        if (isset($config['environment_file'])) {
            $this->assertWorkspacePath($process, $instance, $config['environment_file'], 'environment file');
        }
        foreach (is_array($config['environment'] ?? null) ? $config['environment'] : [] as $value) {
            if (is_string($value) && str_starts_with($value, '/')) {
                $this->assertWorkspacePath($process, $instance, $value, 'absolute environment path');
            }
        }
    }

    private function assertWorkspacePath(Process $process, Instance $instance, mixed $path, string $field): void
    {
        $checkout = rtrim($instance->checkout_path, '/');
        // After cutover the record is already container-relative; the Node's checkout_path names its mounted workspace.
        $roots = $checkout === InstanceContainer::workspaceOnNode($instance) ? [$checkout, InstanceHostIntent::Workspace] : [$checkout];
        if (! is_string($path) || ! array_any($roots, static fn (string $root): bool => $path === $root || str_starts_with($path, $root.'/'))
            || preg_match('~[\x00-\x1f]|(?:^|/)(?:\.|\.\.)(?:/|$)|//~', $path) === 1) {
            $this->refuse($process, 'path_unsupported', "its {$field} must stay inside the workspace without dot segments");
        }
    }

    private function refuse(Process $process, string $reason, string $message): never
    {
        throw new ResourceOperationException('process.container_'.$reason, "Process [{$process->id}:{$process->name}] cannot run in the container: {$message}.", 409);
    }

    /** The host units that migration stops, restores on failure and retires after cutover.
     * @return list<array{name:string,path:string,environment_directory:string,desired:string}>
     */
    public function source(Instance $instance): array
    {
        $records = $this->records([$instance->id]);
        $port = $this->vitePort($instance, $records);
        $units = [];
        foreach ($records as $process) {
            if (array_key_exists('preset', $process->runtime_config)) {
                $this->assertPreset($process, $instance, $port);
            }
            $target = $this->targets->forInspection($process);
            $units[] = ['name' => $this->systemd->unitName($process), 'path' => $this->systemd->unitPath($process),
                'environment_directory' => dirname($this->systemd->environmentPath($process, $target)),
                'desired' => $process->desired_state->value];
        }

        return $units;
    }

    /** @param SupportCollection<int, Instance> $instances */
    public function loadForSites(SupportCollection $instances, bool $refresh = false): void
    {
        $missing = $instances->reject(static fn (Instance $instance): bool => ! $refresh && $instance->relationLoaded('processes'));
        if ($missing->isEmpty()) {
            return;
        }
        $records = $this->records($missing->map(static fn (Instance $instance): int => $instance->id)->all())->groupBy('owner_id');
        foreach ($missing as $instance) {
            $instance->setRelation('processes', $records->get($instance->id, new Collection));
        }
    }

    /**
     * @param  array<int, int>  $instanceIds
     * @return Collection<int, Process>
     */
    private function records(array $instanceIds): Collection
    {
        return Process::query()->whereIn('owner_type', Instance::morphTypes())->whereIn('owner_id', $instanceIds)
            ->where('status', '!=', LifecycleStatus::Removing)->orderBy('id')->get();
    }

    private function hasEnvironment(Process $process, ProcessTarget $target): bool
    {
        return $this->systemd->environmentContent($process, $target) !== '# Orbit Process '.$process->id."\n";
    }
}
