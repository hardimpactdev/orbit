<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Contracts\RemoteShell;
use App\Models\App;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use RuntimeException;

final readonly class EnsureWorkspaceProxyRoute
{
    public function __construct(
        private RemoteShell $remoteShell,
        private WorkspaceFpmPoolRenderer $fpmPoolRenderer,
    ) {}

    /**
     * @return list<array<string, string>>
     */
    public function handle(Workspace $workspace): array
    {
        $workspace->loadMissing(['app', 'app.node']);

        $app = $workspace->app;

        if (! $app instanceof App) {
            throw new RuntimeException("Workspace '{$workspace->name}' has no parent app.");
        }

        $node = $app->node;

        if (! $node instanceof Node) {
            throw new RuntimeException("App '{$app->name}' has no owning node.");
        }

        $domain = $this->domain($workspace, $app, $node);
        $config = [
            'document_root' => $this->documentRoot($workspace, $app),
            'php_socket' => $this->socketPath($workspace),
            'tls' => 'internal',
        ];
        $content = $this->renderCaddySite($workspace, $app, $domain, $config);

        ProxyRoute::query()->updateOrCreate(
            ['domain' => $domain],
            [
                'node_id' => $node->id,
                'app_id' => $app->id,
                'workspace_id' => $workspace->id,
                'owner_type' => 'workspace',
                'kind' => 'workspace',
                'config' => $config,
                'source_hash' => hash('sha256', $content),
            ],
        );

        $result = $this->remoteShell->run($node, $this->renderInstallScript($domain, $content));

        if ($result->successful()) {
            return [];
        }

        return [[
            'code' => 'proxy.enactment_failed',
            'family' => 'proxy',
            'message' => "Proxy route '{$domain}' was recorded, but backend enactment failed. Run doctor to converge proxy artifacts.",
            'next_command' => 'doctor --fix --family=proxy --restore',
        ]];
    }

    /**
     * @param  array{document_root: string, php_socket: string, tls: string}  $config
     */
    private function renderCaddySite(Workspace $workspace, App $app, string $domain, array $config): string
    {
        $pathBlocking = $app->document_root === '.'
            ? 'import path_blocking_project_root'
            : 'import path_blocking_public_root';

        return <<<CADDY
{$domain} {
    tls {$config['tls']}
    root * {$config['document_root']}
    encode gzip

    import security_headers
    import profiling_headers
    {$pathBlocking}
    import security_txt
    import cache_headers
    php_fastcgi unix/{$config['php_socket']}
    file_server
}

CADDY;
    }

    private function renderInstallScript(string $domain, string $content): string
    {
        $sitePath = "/etc/caddy/sites/{$domain}.caddy";

        return sprintf(
            <<<'SH'
sudo mkdir -p /etc/caddy/sites
printf %%s %s | base64 -d | sudo tee %s >/dev/null
sudo systemctl reload caddy
SH,
            escapeshellarg(base64_encode($content)),
            escapeshellarg($sitePath),
        );
    }

    private function domain(Workspace $workspace, App $app, Node $node): string
    {
        $tld = is_string($node->tld) ? trim($node->tld, '.') : '';

        if ($tld === '') {
            return "{$workspace->name}.{$app->name}";
        }

        return "{$workspace->name}.{$app->name}.{$tld}";
    }

    private function documentRoot(Workspace $workspace, App $app): string
    {
        $root = trim((string) $app->document_root, '/');

        if ($root === '') {
            return rtrim((string) $workspace->path, '/');
        }

        return rtrim((string) $workspace->path, '/').'/'.$root;
    }

    private function socketPath(Workspace $workspace): string
    {
        return $this->fpmPoolRenderer->socketPath($workspace);
    }
}
