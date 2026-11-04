<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use Illuminate\Support\Facades\Config;

/**
 * The Gateway Node's own static runtime: its PHP-FPM pool, host retention, the agent-view subscriber and the runtime
 * hibernator. A release changes none of them, so the gateway role's convergence owns them. It also removes what the
 * retired release unit left: its timer and services, and the Documents PHP-FPM start hook.
 */
final readonly class GatewayRuntimeFiles
{
    public const string Pool = '/etc/php/8.5/fpm/pool.d/orbit-gateway.conf';

    /** The release unit's files; its timer is stopped before they go. */
    public const array ReleaseUnits = ['orbit-gateway-release.timer', 'orbit-gateway-release.service', 'orbit-gateway-release-run@.service'];

    /** @return array{directories: list<array<string, string>>, files: list<array<string, string>>, units: list<array{name: string, unit: string}>} */
    public function render(): array
    {
        $application = Config::string('orbit.gateway_checkout');
        $home = Config::string('orbit.home');
        $file = static fn (string $path, string $content, string $ensure = 'present'): array => ['path' => $path, 'ensure' => $ensure,
            'content' => $content, 'owner' => 'root', 'group' => 'root', 'mode' => '0644'];
        $service = static fn (string $description, string $type, string $command, string $extra = ''): string => "[Unit]\nDescription={$description}\n"
            ."After=network-online.target\nWants=network-online.target\n\n[Service]\nType={$type}\nUser=orbit\nWorkingDirectory={$application}\n"
            ."Environment=ORBIT_HOME={$home}\nExecStart=\"/usr/bin/php8.5\" \"{$application}/artisan\" \"{$command}\"\n{$extra}";
        $log = $application.'/storage/logs/laravel.log';

        $directory = static fn (string $path): array => ['path' => $path, 'owner' => 'root', 'group' => 'root', 'mode' => '0755'];

        return [
            'directories' => [$directory('/etc/systemd/journald.conf.d'), $directory('/etc/systemd/system/logrotate.timer.d')],
            'files' => [
                $file(self::Pool, $this->pool($application, $home)),
                $file('/etc/systemd/system/orbit-runtime-hibernator.service', $service('Orbit runtime hibernator', 'oneshot', 'orbit:runtime-hibernator')),
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
                ['name' => 'orbit-agent-view.service', 'unit' => $service('Orbit agent view subscriber', 'simple', 'orbit:agent-view',
                    "Restart=always\nRestartSec=2\nMemoryMax=256M\n\n[Install]\nWantedBy=multi-user.target\n")],
                ['name' => 'orbit-runtime-hibernator.timer', 'unit' => "[Unit]\nDescription=Orbit runtime hibernator timer\n\n[Timer]\nOnBootSec=2min\n"
                    .'OnUnitActiveSec='.Config::integer('orbit.hibernation.sweep_seconds')."s\nPersistent=true\nUnit=orbit-runtime-hibernator.service\n\n[Install]\nWantedBy=timers.target\n"],
            ],
        ];
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
