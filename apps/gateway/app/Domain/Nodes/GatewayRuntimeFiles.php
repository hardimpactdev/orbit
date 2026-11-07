<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Infrastructure\Gateway\GatewayHorizon;
use App\Infrastructure\Gateway\GatewayQueueRedis;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Support\Facades\Config;

/**
 * The Gateway Node's own static runtime: its PHP-FPM pool, host retention, the agent-view subscriber, the runtime
 * hibernator, and the scheduler and Horizon units where no Process runs them. A release changes none of them, so the gateway role's convergence owns them. It also removes what the
 * retired release unit left: its timer and services, and the Documents PHP-FPM start hook.
 */
final readonly class GatewayRuntimeFiles
{
    public const string Pool = '/etc/php/8.5/fpm/pool.d/orbit-gateway.conf';

    /**
     * Settings of the Gateway environment that no code reads any more. The gateway role's convergence removes them from the shared env
     * file; a key leaves this list once no Gateway has it (docs/reference/old-paths.md).
     */
    public const array RetiredSettings = ['ORBIT_TASK_VMS_UPCLOUD_TOKEN_FILE', 'ORBIT_TASK_VMS_UPCLOUD_MAX_VMS', 'ORBIT_TASK_VMS_UPCLOUD_PLAN',
        'ORBIT_TASK_VMS_UPCLOUD_DISK_GB'];

    /** The release unit's files; its timer is stopped before they go. */
    public const array ReleaseUnits = ['orbit-gateway-release.timer', 'orbit-gateway-release.service', 'orbit-gateway-release-run@.service'];

    /**
     * The scheduler and Horizon run as the Gateway Node's Processes `schedule-work` and `horizon` where an operator made them. A
     * Gateway without those Processes gets the units here, so its role is all it needs to record finished runs. Horizon runs
     * only when a queue is configured. Both units own `/run/orbit`, which the Documents cleanup state needs under the `orbit` user.
     *
     * @return array{directories: list<array<string, string>>, files: list<array<string, string>>, units: list<array{name: string, unit: string}>}
     */
    public function render(?Node $node = null): array
    {
        $application = Config::string('orbit.gateway_checkout');
        $home = Config::string('orbit.home');
        $file = static fn (string $path, string $content, string $ensure = 'present'): array => ['path' => $path, 'ensure' => $ensure,
            'content' => $content, 'owner' => 'root', 'group' => 'root', 'mode' => '0644'];
        $log = str_replace(['\\', '"'], ['\\\\', '\\"'], $application.'/storage/logs/laravel.log');

        $directory = static fn (string $path): array => ['path' => $path, 'owner' => 'root', 'group' => 'root', 'mode' => '0755'];

        return [
            'directories' => [$directory('/etc/systemd/journald.conf.d'), $directory('/etc/systemd/system/logrotate.timer.d')],
            'files' => [
                $file(self::Pool, $this->pool($application, $home)),
                $file('/etc/systemd/system/orbit-runtime-hibernator.service', $this->service('Orbit runtime hibernator', 'oneshot', 'orbit:runtime-hibernator')),
                $file('/etc/systemd/journald.conf.d/orbit-retention.conf', "# Managed by Orbit.\n[Journal]\nSystemMaxUse=".Config::integer('retention.journal_max_mb')
                    ."M\nSystemKeepFree=".Config::integer('retention.journal_keep_free_mb')."M\nRuntimeMaxUse=".Config::integer('retention.journal_runtime_max_mb')
                    ."M\nMaxRetentionSec=".Config::integer('retention.journal_days')."day\n"),
                $file('/etc/logrotate.d/orbit-gateway', "# Managed by Orbit. Daily Laravel logs rotate through Laravel itself.\n\"{$log}\" {\n    su orbit orbit\n    daily\n"
                    .'    maxsize '.Config::integer('retention.log_rotate_mb')."M\n    rotate ".Config::integer('retention.log_rotate_keep')
                    ."\n    missingok\n    notifempty\n    compress\n    copytruncate\n}\n"),
                $file('/etc/systemd/system/logrotate.timer.d/orbit-retention.conf', "# Managed by Orbit.\n[Timer]\nOnCalendar=\nOnCalendar=hourly\nAccuracySec=1m\nRandomizedDelaySec=0\nPersistent=false\n"),
                ...array_map(static fn (string $unit): array => $file('/etc/systemd/system/'.$unit, '', 'absent'), self::ReleaseUnits),
                $file('/etc/systemd/system/php8.5-fpm.service.d/orbit-document-cleanup.conf', '', 'absent'),
                $file('/etc/orbit/php-fpm-start-hooks', '', 'absent'),
            ],
            'units' => [
                ['name' => 'orbit-agent-view.service', 'unit' => $this->service('Orbit agent view subscriber', 'simple', 'orbit:agent-view',
                    "Restart=always\nRestartSec=2\nMemoryMax=256M\n\n[Install]\nWantedBy=multi-user.target\n")],
                ...$this->recorder($node),
                ['name' => 'orbit-runtime-hibernator.timer', 'unit' => "[Unit]\nDescription=Orbit runtime hibernator timer\n\n[Timer]\nOnBootSec=2min\n"
                    .'OnUnitActiveSec='.Config::integer('orbit.hibernation.sweep_seconds')."s\nPersistent=true\nUnit=orbit-runtime-hibernator.service\n\n[Install]\nWantedBy=timers.target\n"],
            ],
        ];
    }

    /**
     * The scheduler and Horizon, which record finished runs, where no Process of the Node runs them. The bootstrap of a new
     * Gateway starts the same units before its first run (docs/cli/tasks/vm-fleet-build.mmd), so that run is recorded before
     * the role converges; the role then finds them unchanged.
     *
     * @return list<array{name: string, unit: string}>
     */
    public function recorder(?Node $node = null): array
    {
        $owned = $node instanceof Node
            ? Process::query()->where('owner_type', Node::class)->where('owner_id', $node->id)->pluck('name')->all()
            : [];
        $longRunning = fn (string $name, string $description, string $artisan, string $stop = '', string ...$after): array => ['name' => "orbit-gateway-{$name}.service",
            'unit' => $this->service($description, 'simple', $artisan, "Restart=always\nRestartSec=2\n{$stop}RuntimeDirectory=orbit\nRuntimeDirectoryPreserve=yes\n\n[Install]\nWantedBy=multi-user.target\n", ...$after)];

        return [
            ...in_array('schedule-work', $owned, true) ? [] : [$longRunning('schedule', 'Orbit Gateway scheduler', 'schedule:work')],
            ...in_array('horizon', $owned, true) || ! GatewayQueueRedis::configured() ? [] : [$longRunning('horizon', 'Orbit Gateway queue', 'horizon', implode("\n", [...GatewayHorizon::stopDirectives(), '']), 'valkey-server.service')],
        ];
    }

    /**
     * `$after` orders the unit after more units: Horizon drains through its queue, so it stops before a Valkey on the same machine (no-op without one).
     */
    private function service(string $description, string $type, string $artisan, string $extra = '', string ...$after): string
    {
        $application = Config::string('orbit.gateway_checkout');

        return "[Unit]\nDescription={$description}\nAfter=".implode(' ', ['network-online.target', ...$after])."\nWants=network-online.target\n\n[Service]\nType={$type}\nUser=orbit\n"
            ."WorkingDirectory={$this->directive($application)}\nEnvironment=ORBIT_HOME={$this->directive(Config::string('orbit.home'))}\n"
            ."ExecStart=\"/usr/bin/php8.5\" {$this->argument($application.'/artisan')} \"{$artisan}\"\n{$extra}";
    }

    /** A systemd directive value, escaped as the agent wrote it: `%` doubles, and blanks, quotes, `$` and `\` become `\xNN`. */
    private function directive(string $value): string
    {
        return (string) preg_replace_callback('/[\x00-\x20"\'$\\\\\x7f]|%/', static fn (array $match): string => $match[0] === '%' ? '%%'
            : sprintf('\\x%02x', ord($match[0])), $value);
    }

    /** A quoted systemd command argument, escaped as the agent wrote it. */
    private function argument(string $value): string
    {
        return '"'.str_replace(['\\', '"', '$', '%'], ['\\\\', '\\"', '$$', '%%'], $value).'"';
    }

    private function pool(string $application, string $home): string
    {
        return <<<FPM
            [orbit-gateway]
            user = orbit
            group = orbit
            listen = /run/php/orbit-gateway.sock
            listen.owner = orbit
            listen.group = caddy
            listen.mode = 0660
            pm = ondemand
            pm.max_children = 8
            pm.process_idle_timeout = 10s
            pm.max_requests = 100
            request_terminate_timeout = 600s
            chdir = {$application}
            catch_workers_output = yes
            clear_env = yes
            security.limit_extensions = .php
            env[HOME] = /home/orbit
            env[ORBIT_HOME] = {$home}
            env[PATH] = /usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
            php_admin_value[opcache.validate_timestamps] = 1
            php_admin_value[opcache.revalidate_freq] = 0
            php_admin_value[max_execution_time] = 600
            FPM.PHP_EOL;
    }
}
