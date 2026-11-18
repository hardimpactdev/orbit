<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Instances\ContainerProcessCommands;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessTarget;
use App\Domain\Schedules\DesiredTimerState;
use App\Domain\Schedules\ScheduleTrigger;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Instances\ProductionApplicationPaths;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Models\Instance;
use App\Models\Process;
use App\Models\Schedule;
use App\Support\ValidatedData;

final readonly class ProductionContainerUnits
{
    /** @return array{units:list<array<string,mixed>>,processes:list<array{unit:string,desired:string}>,timers:list<string>,host_units:list<string>} */
    public function forInstance(Instance $instance): array
    {
        $checkout = '/srv/workspace/current';
        $directory = $checkout.ProductionApplicationPaths::suffix($instance->root ?? $instance->project->root);
        $target = new ProcessTarget($instance->node, 'orbit', $directory, instance: $instance, environmentFile: $directory.'/.env',
            container: ['instance_id' => $instance->id, 'project' => 'orbit-releases', 'name' => 'prepare-instance-'.$instance->id]);
        $renderer = app(SystemdProcessRenderer::class);
        $units = $processes = $timers = $host = [];
        $paths = array_filter([$instance->checkout_path => $checkout, ($instance->production_home ?? '').'/current' => $checkout], fn ($v, $k): bool => $k !== '' && $k !== '/current', ARRAY_FILTER_USE_BOTH);
        foreach (Process::query()->whereIn('owner_type', Instance::morphTypes())->where('owner_id', $instance->id)->orderBy('id')->get() as $process) {
            if ($process->runtime !== ProcessRuntime::Systemd || $process->retired_runtime !== null) {
                throw new ResourceOperationException('deployment.process_unsupported', 'Resolve non-systemd or retired Process definitions before migrating this Instance.', 409);
            }
            $name = $renderer->unitName($process);
            $host[] = $name;
            $config = $process->runtime_config;
            $config['command'] = app(ContainerProcessCommands::class)->resolve(ValidatedData::stringList($config['command'] ?? []), $instance->selected_php_version ?? $instance->project->php_version, $name, hostPaths: true);
            array_walk_recursive($config['command'], static function (string &$arg) use ($paths): void {
                $arg = strtr($arg, $paths);
            });
            $config['environment_file'] = $directory.'/.env';
            $process->runtime_config = $config;
            $process->working_directory = strtr($process->working_directory ?? $directory, $paths);
            if ($process->working_directory !== $checkout && ! str_starts_with($process->working_directory, $checkout.'/')) {
                throw new ResourceOperationException('deployment.process_unsupported', 'A production container Process must run inside its checkout.', 409);
            }
            $content = $renderer->render($process, $target);
            $path = $renderer->environmentPath($process, $target);
            $units[] = ['name' => $name, 'content' => $content, 'desired' => $process->desired_state->value,
                ...(str_contains($content, 'EnvironmentFile='.$path) ? ['environment_file' => ['path' => $path, 'content' => $renderer->environmentContent($process, $target)]] : [])];
            $processes[] = ['unit' => $name, 'desired' => $process->desired_state->value];
        }

        foreach (Schedule::query()->whereIn('target_type', Instance::morphTypes())->where('target_id', $instance->id)
            ->where('host_node_id', $instance->node_id)->whereIn('trigger', [ScheduleTrigger::Node, ScheduleTrigger::Container])->orderBy('id')->get() as $schedule) {
            if (preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $schedule->id) !== 1
                || preg_match('/[\x00-\x1f\x7f]/', $schedule->calendar) === 1 || $schedule->timeout_seconds < 1) {
                throw new ResourceOperationException('deployment.schedule_invalid', 'The production Schedule has an invalid identity, calendar, or timeout.', 409);
            }
            $prefix = 'orbit-schedule-'.$schedule->id;
            $service = $prefix.'.service';
            $timer = $prefix.'.timer';
            $script = '/etc/orbit/schedules/'.$schedule->id.'.sh';
            $calendar = str_replace('%', '%%', $schedule->calendar);
            $enabled = $schedule->desired_timer_state === DesiredTimerState::Enabled;
            $content = "[Unit]\nDescription=Orbit Schedule {$schedule->id}\nX-Orbit-Schedule-ID={$schedule->id}\n\n[Service]\nType=oneshot\nUser=orbit\nGroup=orbit\nUMask=0007\nWorkingDirectory={$directory}\nEnvironment=HOME=/home/orbit\nEnvironment=PATH=".InstanceContainer::path($instance->project)."\nEnvironmentFile=-{$directory}/.env\nExecStart=/bin/bash {$script}\nTimeoutStartSec={$schedule->timeout_seconds}\nTimeoutStopSec=5\n";
            $units[] = ['name' => $service, 'content' => $content, 'desired' => 'stopped', 'script' => ['path' => $script, 'content' => strtr($schedule->command, $paths)."\n"]];
            $units[] = ['name' => $timer, 'desired' => $enabled ? 'running' : 'stopped',
                'content' => "[Unit]\nDescription=Orbit Schedule timer {$schedule->id}\nX-Orbit-Schedule-ID={$schedule->id}\n\n[Timer]\nOnCalendar={$calendar}\nPersistent=false\nDeferReactivation=true\nAccuracySec=1s\nUnit={$service}\n\n[Install]\nWantedBy=timers.target\n"];
            if ($enabled) {
                $timers[] = $timer;
            }
            $host[] = $timer;
            $host[] = $service;
        }

        return ['units' => $units, 'processes' => $processes, 'timers' => $timers, 'host_units' => $host];
    }
}
