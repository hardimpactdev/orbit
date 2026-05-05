<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Models\App;
use App\Models\Process;
use InvalidArgumentException;

final readonly class SupervisorProgramRenderer
{
    public function render(App $app, Process $process): string
    {
        $app->loadMissing('node');

        $programName = $this->programName($app, $process);
        $user = $app->node?->user ?: ($app->node?->ssh_user ?: 'orbit');
        $home = $user === 'root' ? '/root' : "/home/{$user}";
        $logPath = "{$home}/.config/orbit/logs/{$programName}.log";

        return <<<CONF
[program:{$programName}]
directory={$app->path}
command=/bin/bash -lc {$this->escapeSupervisorValue($process->command)}
user={$user}
autostart=false
autorestart={$process->restart_policy->toSupervisor()}
startsecs=0
redirect_stderr=true
stdout_logfile={$logPath}
stdout_logfile_maxbytes=20MB
stdout_logfile_backups=5
environment={$this->environment($app, $home)}

CONF;
    }

    public function programName(App $app, Process $process): string
    {
        $this->assertIdentitySlug($app->name);
        $this->assertIdentitySlug($process->name);

        return "orbit_{$app->name}_main_{$process->name}";
    }

    private function environment(App $app, string $home): string
    {
        $path = "{$home}/.local/bin:{$home}/.bun/bin:/opt/homebrew/bin:/opt/homebrew/sbin:/usr/local/bin:/usr/bin:/bin";
        $url = $app->url();
        $host = preg_replace('#^https?://#', '', $url) ?: $app->name;
        $tlsBase = "{$home}/.config/orbit/tls/{$host}";

        return collect([
            'PATH' => $path,
            'HOME' => $home,
            'APP_URL' => $url,
            'VITE_APP_URL' => $url,
            'VITE_DEV_SERVER_KEY' => "{$tlsBase}.key",
            'VITE_DEV_SERVER_CERT' => "{$tlsBase}.crt",
        ])->map(fn (string $value, string $key): string => "{$key}=\"{$this->escapeEnvironmentValue($value)}\"")
            ->implode(',');
    }

    private function assertIdentitySlug(string $value): void
    {
        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $value)) {
            throw new InvalidArgumentException("Unsafe runtime unit identity segment: {$value}");
        }
    }

    private function escapeSupervisorValue(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }

    private function escapeEnvironmentValue(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }
}
