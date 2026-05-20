<?php

declare(strict_types=1);

namespace App\Services\Php;

use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Apps\AppFpmPoolRenderer;
use App\Services\Workspaces\WorkspaceFpmPoolRenderer;

final readonly class PhpFpmSystemdHardening
{
    public function __construct(
        private ?AppFpmPoolRenderer $appFpmPoolRenderer = null,
        private ?WorkspaceFpmPoolRenderer $workspaceFpmPoolRenderer = null,
    ) {}

    /**
     * @param  list<string>  $readWritePaths
     */
    public function content(array $readWritePaths = []): string
    {
        $paths = array_values(array_unique(array_filter([
            '/run',
            '/tmp',
            '/var/lib/php/sessions',
            ...$readWritePaths,
        ], fn (string $path): bool => $path !== '')));
        sort($paths);

        $readWritePathsLine = implode(' ', $paths);

        return <<<CONF
[Service]
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectKernelTunables=true
ProtectControlGroups=true
RestrictSUIDSGID=true
LimitNOFILE=65535
LimitNPROC=4096
ReadWritePaths={$readWritePathsLine}
CONF;
    }

    public function contentForNode(Node $node, string $phpVersion): string
    {
        return $this->content($this->readWritePathsForNode($node, $phpVersion));
    }

    /**
     * @return list<string>
     */
    public function readWritePathsForNode(Node $node, string $phpVersion): array
    {
        $paths = [];

        App::query()
            ->where('node_id', $node->id)
            ->where('php_version', $phpVersion)
            ->with('node')
            ->get()
            ->each(function (App $app) use (&$paths): void {
                array_push($paths, ...$this->appFpmPoolRenderer()->readWritePaths($app));
            });

        Workspace::query()
            ->whereHas('app', fn ($query) => $query->where('node_id', $node->id))
            ->with('app.node')
            ->get()
            ->each(function (Workspace $workspace) use (&$paths, $phpVersion): void {
                if ($workspace->effectivePhpVersion() !== $phpVersion) {
                    return;
                }

                array_push($paths, ...$this->workspaceFpmPoolRenderer()->readWritePaths($workspace));
            });

        return array_values(array_unique($paths));
    }

    public function path(string $phpVersion): string
    {
        return "/etc/systemd/system/php{$phpVersion}-fpm.service.d/10-orbit-hardening.conf";
    }

    public function installScript(string $phpVersion, string $content): string
    {
        $path = $this->path($phpVersion);

        return sprintf(
            <<<'SH'
sudo install -d -m 0755 %s
printf %%s %s | base64 -d | sudo tee %s >/dev/null
sudo systemctl daemon-reload
SH,
            escapeshellarg(dirname($path)),
            escapeshellarg(base64_encode($content)),
            escapeshellarg($path),
        );
    }

    private function appFpmPoolRenderer(): AppFpmPoolRenderer
    {
        return $this->appFpmPoolRenderer ?? app(AppFpmPoolRenderer::class);
    }

    private function workspaceFpmPoolRenderer(): WorkspaceFpmPoolRenderer
    {
        return $this->workspaceFpmPoolRenderer ?? app(WorkspaceFpmPoolRenderer::class);
    }
}
