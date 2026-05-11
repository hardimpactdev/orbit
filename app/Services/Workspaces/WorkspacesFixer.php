<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Php\PhpFpmServiceReloader;

final readonly class WorkspacesFixer
{
    public function __construct(
        private RemoteShell $remoteShell,
        private WorkspaceFpmPoolRenderer $fpmPoolRenderer,
        private PhpFpmServiceReloader $fpmServiceReloader,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function fix(Workspace $workspace, DriftEntry $entry): ?array
    {
        if (! in_array($entry->key, ['workspace.fpm_config_missing', 'workspace.fpm_config_mismatch'], true)) {
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

        $this->remoteShell->run($node, $this->installScript($workspace, $path, $content, $service), ['throw' => true]);

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

    private function installScript(Workspace $workspace, string $path, string $content, string $service): string
    {
        return sprintf(
            <<<'SH'
set -e
sudo install -d -m 0755 %s %s %s
printf %%s %s | base64 -d | sudo tee %s >/dev/null
%s
SH,
            escapeshellarg(dirname($path)),
            escapeshellarg(dirname($this->fpmPoolRenderer->socketPath($workspace))),
            escapeshellarg(dirname($this->fpmPoolRenderer->logPath($workspace))),
            escapeshellarg(base64_encode($content)),
            escapeshellarg($path),
            $this->fpmServiceReloader->reloadOrRestartScript($service),
        );
    }
}
