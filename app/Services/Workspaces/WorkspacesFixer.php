<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Php\PhpFpmServiceReloader;
use App\Services\Php\PhpFpmSystemdHardening;

final readonly class WorkspacesFixer
{
    public function __construct(
        private RemoteShell $remoteShell,
        private WorkspaceFpmPoolRenderer $fpmPoolRenderer,
        private PhpFpmServiceReloader $fpmServiceReloader,
        private PhpFpmSystemdHardening $fpmSystemdHardening,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function fix(Workspace $workspace, DriftEntry $entry): ?array
    {
        if (! in_array($entry->key, [
            'workspace.fpm_config_missing',
            'workspace.fpm_config_mismatch',
            'workspace.security.system_user',
            'workspace.security.fs_permissions',
            'workspace.security.fpm_pool_isolation',
            'workspace.security.fpm_systemd_hardening',
        ], true)) {
            return null;
        }

        $workspace->loadMissing('app.node');

        $node = $workspace->app?->node;

        if (! $node instanceof Node) {
            return null;
        }

        $content = $this->fpmPoolRenderer->content($workspace);
        $path = $this->fpmPoolRenderer->path($workspace);
        $service = $this->fpmPoolRenderer->service($workspace);
        $hardening = $this->fpmSystemdHardening->contentForNode($node, (string) $workspace->effectivePhpVersion());

        $this->remoteShell->run($node, $this->installScript($workspace, $path, $content, $service, $hardening), ['throw' => true]);

        return [
            'family' => 'workspace',
            'node' => $node->name,
            'code' => $entry->key,
            'key' => $entry->key,
            'mode' => 'fix',
            'status' => 'completed',
            'summary' => "Re-applied workspace PHP-FPM pool for {$workspace->name}.",
            'details' => [
                'workspace' => $workspace->name,
                'app' => $workspace->app->name,
                'path' => $path,
            ],
        ];
    }

    private function installScript(Workspace $workspace, string $path, string $content, string $service, string $hardening): string
    {
        $user = $this->fpmPoolRenderer->runtimeUser($workspace);
        $home = $user === 'root' ? '/root' : "/home/{$user}";
        $phpVersion = (string) $workspace->effectivePhpVersion();

        return sprintf(
            <<<'SH'
set -e
if ! id -u %s >/dev/null 2>&1; then
    sudo useradd --system --create-home --home-dir %s --shell /usr/sbin/nologin %s
fi
sudo install -d -m 0750 -o %s -g %s %s
sudo install -d -m 0755 %s %s %s
if [ -d %s ]; then sudo chown -R %s:%s %s; fi
printf %%s %s | base64 -d | sudo tee %s >/dev/null
%s
%s
SH,
            escapeshellarg($user),
            escapeshellarg($home),
            escapeshellarg($user),
            escapeshellarg($user),
            escapeshellarg($user),
            escapeshellarg($home),
            escapeshellarg(dirname($path)),
            escapeshellarg(dirname($this->fpmPoolRenderer->socketPath($workspace))),
            escapeshellarg(dirname($this->fpmPoolRenderer->logPath($workspace))),
            escapeshellarg($workspace->path),
            escapeshellarg($user),
            escapeshellarg($user),
            escapeshellarg($workspace->path),
            escapeshellarg(base64_encode($content)),
            escapeshellarg($path),
            $this->fpmSystemdHardening->installScript($phpVersion, $hardening),
            $this->fpmServiceReloader->reloadOrRestartScript($service),
        );
    }
}
