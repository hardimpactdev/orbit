<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Contracts\RemoteShell;
use App\Models\App;
use App\Models\ProxyRoute;
use RuntimeException;

final readonly class EnsureAppProxyRoute
{
    public function __construct(
        private RemoteShell $remoteShell,
    ) {}

    /**
     * @return list<array<string, string>>
     */
    public function handle(App $app): array
    {
        $app->loadMissing('node');

        if ($app->node === null) {
            throw new RuntimeException("App '{$app->name}' has no owning node.");
        }

        $domain = $this->domain($app);
        $config = [
            'document_root' => $app->documentRootPath(),
            'php_socket' => $this->socketPath($app),
            'tls' => 'internal',
        ];
        $content = $this->renderCaddySite($app, $domain, $config);

        ProxyRoute::query()->updateOrCreate(
            ['domain' => $domain],
            [
                'node_id' => $app->node->id,
                'app_id' => $app->id,
                'owner_type' => 'app',
                'kind' => 'app',
                'config' => $config,
                'source_hash' => hash('sha256', $content),
            ],
        );

        $result = $this->remoteShell->run($app->node, $this->renderInstallScript($domain, $content));

        if ($result->successful()) {
            return [];
        }

        return [[
            'code' => 'proxy.enactment_failed',
            'family' => 'proxy',
            'message' => "Proxy route '{$domain}' was recorded, but backend enactment failed. Run doctor to converge proxy artifacts.",
            'next_command' => 'doctor --family=proxy --fix',
        ]];
    }

    /**
     * @param  array{document_root: string, php_socket: string, tls: string}  $config
     */
    private function renderCaddySite(App $app, string $domain, array $config): string
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
cat <<'ORBIT_CADDY_SITE' | sudo tee %s >/dev/null
%s
ORBIT_CADDY_SITE
sudo systemctl reload caddy
SH,
            escapeshellarg($sitePath),
            $content,
        );
    }

    private function domain(App $app): string
    {
        if (is_string($app->domain) && $app->domain !== '') {
            return $app->domain;
        }

        $tld = is_string($app->node?->tld) ? trim($app->node->tld, '.') : '';

        if ($tld === '') {
            return $app->name;
        }

        return "{$app->name}.{$tld}";
    }

    private function socketPath(App $app): string
    {
        $user = $app->node?->user ?: ($app->node?->ssh_user ?: 'orbit');
        $home = $user === 'root' ? '/root' : "/home/{$user}";

        return "{$home}/.config/orbit/php/{$app->name}.sock";
    }
}
