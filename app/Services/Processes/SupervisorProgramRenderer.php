<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Data\RuntimeBackend\SupervisorProgramDefinition;
use App\Models\App;
use App\Models\Process;
use App\Services\RuntimeBackend\SupervisorProgramRenderer as RuntimeBackendSupervisorProgramRenderer;
use InvalidArgumentException;

final readonly class SupervisorProgramRenderer
{
    public function __construct(
        private RuntimeBackendSupervisorProgramRenderer $renderer = new RuntimeBackendSupervisorProgramRenderer,
    ) {}

    public function render(App $app, Process $process): string
    {
        return $this->renderer->render($this->definition($app, $process));
    }

    public function installScript(App $app, Process $process): string
    {
        return $this->renderer->renderInstallScript($this->definition($app, $process));
    }

    public function definition(App $app, Process $process): SupervisorProgramDefinition
    {
        $app->loadMissing('node');

        $programName = $this->programName($app, $process);
        $user = $app->node?->user ?: ($app->node?->ssh_user ?: 'orbit');
        $home = $user === 'root' ? '/root' : "/home/{$user}";
        $logPath = "{$home}/.config/orbit/logs/{$programName}.log";

        return new SupervisorProgramDefinition(
            name: $programName,
            directory: $app->path,
            command: $process->command,
            user: $user,
            restartPolicy: $process->restart_policy->toSupervisor(),
            stdoutLogFile: $logPath,
            environment: $this->environment($app, $home),
        );
    }

    public function programName(App $app, Process $process): string
    {
        $this->assertIdentitySlug($app->name);
        $this->assertIdentitySlug($process->name);

        return "orbit_{$app->name}_main_{$process->name}";
    }

    /**
     * @return array<string, string>
     */
    private function environment(App $app, string $home): array
    {
        $path = "{$home}/.local/bin:{$home}/.bun/bin:/opt/homebrew/bin:/opt/homebrew/sbin:/usr/local/bin:/usr/bin:/bin";
        $url = $app->url();
        $host = preg_replace('#^https?://#', '', $url) ?: $app->name;
        $tlsBase = "{$home}/.config/orbit/tls/{$host}";

        return [
            'PATH' => $path,
            'HOME' => $home,
            'APP_URL' => $url,
            'VITE_APP_URL' => $url,
            'VITE_DEV_SERVER_KEY' => "{$tlsBase}.key",
            'VITE_DEV_SERVER_CERT' => "{$tlsBase}.crt",
        ];
    }

    private function assertIdentitySlug(string $value): void
    {
        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $value)) {
            throw new InvalidArgumentException("Unsafe runtime unit identity segment: {$value}");
        }
    }
}
