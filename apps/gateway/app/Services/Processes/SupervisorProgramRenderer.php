<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Contracts\AppRuntimeUserResolver;
use App\Contracts\WorkspaceRuntimeUserResolver;
use App\Data\RuntimeBackend\SupervisorProgramDefinition;
use App\Models\App;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Apps\AppRuntimeUser;
use App\Services\RuntimeBackend\SupervisorProgramRenderer as RuntimeBackendSupervisorProgramRenderer;
use App\Services\Workspaces\WorkspaceRuntimeUser;
use InvalidArgumentException;

final readonly class SupervisorProgramRenderer
{
    public function __construct(
        private RuntimeBackendSupervisorProgramRenderer $renderer = new RuntimeBackendSupervisorProgramRenderer,
        private AppRuntimeUserResolver $appRuntimeUser = new AppRuntimeUser,
        private WorkspaceRuntimeUserResolver $workspaceRuntimeUser = new WorkspaceRuntimeUser,
    ) {}

    public function render(App $app, Process $process, ?Workspace $workspace = null): string
    {
        return $this->renderer->render($this->definition($app, $process, $workspace));
    }

    public function installScript(App $app, Process $process, ?Workspace $workspace = null): string
    {
        return $this->renderer->renderInstallScript($this->definition($app, $process, $workspace));
    }

    public function configPath(App $app, Process $process, ?Workspace $workspace = null): string
    {
        return $this->renderer->configPath($this->definition($app, $process, $workspace));
    }

    public function host(App $app, ?Workspace $workspace = null): string
    {
        $url = $workspace instanceof Workspace ? $workspace->url() : $app->url();
        $host = parse_url($url, PHP_URL_HOST);

        if (is_string($host) && $host !== '') {
            return $host;
        }

        return preg_replace('#^https?://#', '', $url) ?: $app->name;
    }

    public function definition(App $app, Process $process, ?Workspace $workspace = null): SupervisorProgramDefinition
    {
        $app->loadMissing('node');

        $programName = $this->programName($app, $process, $workspace);
        $user = $this->runtimeUser($app, $workspace);
        $home = $user === 'root' ? '/root' : "/home/{$user}";
        $logPath = "{$home}/.config/orbit/logs/{$programName}.log";

        return new SupervisorProgramDefinition(
            name: $programName,
            directory: $this->directory($app, $workspace),
            command: $process->command,
            user: $user,
            restartPolicy: $process->restart_policy->toSupervisor(),
            stdoutLogFile: $logPath,
            environment: $this->environment($app, $process, $home, $workspace),
        );
    }

    public function programName(App $app, Process $process, ?Workspace $workspace = null): string
    {
        $this->assertIdentitySlug($app->name);
        $this->assertIdentitySlug($process->name);

        if ($workspace instanceof Workspace) {
            $this->assertIdentitySlug($workspace->name);

            return "orbit_{$app->name}_{$workspace->name}_{$process->name}";
        }

        return "orbit_{$app->name}_main_{$process->name}";
    }

    /**
     * @return array<string, string>
     */
    private function environment(App $app, Process $process, string $home, ?Workspace $workspace): array
    {
        $path = "{$home}/.local/bin:{$home}/.bun/bin:/opt/homebrew/bin:/opt/homebrew/sbin:/usr/local/bin:/usr/bin:/bin";
        $url = $workspace instanceof Workspace ? $workspace->url() : $app->url();
        $tlsBase = "{$home}/.config/orbit/certs/{$this->host($app, $workspace)}";

        return [
            'PATH' => $path,
            'HOME' => $home,
            'APP_URL' => $url,
            'VITE_APP_URL' => $url,
            'VITE_VALET_HOST' => $this->host($app, $workspace),
            'VITE_DEV_SERVER_KEY' => "{$tlsBase}.key",
            'VITE_DEV_SERVER_CERT' => "{$tlsBase}.crt",
        ];
    }

    private function directory(App $app, ?Workspace $workspace): string
    {
        $path = trim((string) ($workspace instanceof Workspace ? $workspace->path : $app->path));

        if ($path === '') {
            $owner = $workspace instanceof Workspace ? "Workspace '{$workspace->name}'" : "App '{$app->name}'";

            throw new InvalidArgumentException("{$owner} has no source path; cannot render Supervisor program.");
        }

        return $path === '/' ? $path : rtrim($path, '/');
    }

    private function runtimeUser(App $app, ?Workspace $workspace): string
    {
        $user = trim($workspace instanceof Workspace
            ? $this->workspaceRuntimeUser->forWorkspace($workspace)
            : $this->appRuntimeUser->forApp($app));

        if ($user === '') {
            $owner = $workspace instanceof Workspace ? "Workspace '{$workspace->name}'" : "App '{$app->name}'";

            throw new InvalidArgumentException("{$owner} has no runtime user; cannot render Supervisor program.");
        }

        return $user;
    }

    private function assertIdentitySlug(string $value): void
    {
        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $value)) {
            throw new InvalidArgumentException("Unsafe runtime unit identity segment: {$value}");
        }
    }
}
