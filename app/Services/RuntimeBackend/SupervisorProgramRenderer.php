<?php

declare(strict_types=1);

namespace App\Services\RuntimeBackend;

use App\Data\RuntimeBackend\SupervisorProgramDefinition;
use InvalidArgumentException;

final readonly class SupervisorProgramRenderer
{
    public function render(SupervisorProgramDefinition $program): string
    {
        $this->assertProgramName($program->name);

        $lines = [
            "[program:{$program->name}]",
            "directory={$program->directory}",
            "command=/bin/bash -lc {$this->escapeSupervisorValue($program->command)}",
            "user={$program->user}",
            'autostart='.($program->autostart ? 'true' : 'false'),
            "autorestart={$program->restartPolicy}",
            "startsecs={$program->startSeconds}",
            'redirect_stderr='.($program->redirectStderr ? 'true' : 'false'),
            "stdout_logfile={$program->stdoutLogFile}",
            "stdout_logfile_maxbytes={$program->stdoutLogFileMaxBytes}",
            "stdout_logfile_backups={$program->stdoutLogFileBackups}",
        ];

        if ($program->environment !== []) {
            $lines[] = 'environment='.$this->renderEnvironment($program->environment);
        }

        return implode("\n", $lines)."\n";
    }

    public function configPath(SupervisorProgramDefinition $program): string
    {
        $this->assertProgramName($program->name);

        return "/etc/supervisor/conf.d/{$program->name}.conf";
    }

    public function renderInstallScript(SupervisorProgramDefinition $program): string
    {
        return sprintf(
            <<<'SH'
sudo mkdir -p /etc/supervisor/conf.d
printf %%s %s | base64 -d | sudo tee %s >/dev/null
sudo supervisorctl reread
sudo supervisorctl update %s
SH,
            escapeshellarg(base64_encode($this->render($program))),
            escapeshellarg($this->configPath($program)),
            escapeshellarg($program->name),
        );
    }

    /**
     * @param  array<string, string>  $environment
     */
    private function renderEnvironment(array $environment): string
    {
        return collect($environment)
            ->map(fn (string $value, string $key): string => "{$key}=\"{$this->escapeEnvironmentValue($value)}\"")
            ->implode(',');
    }

    private function assertProgramName(string $value): void
    {
        if (! preg_match('/^[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?$/', $value)) {
            throw new InvalidArgumentException("Unsafe Supervisor program name: {$value}");
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
