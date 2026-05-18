<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Models\App;
use App\Models\ProxyRoute;
use App\Services\Gateway\CaddyGlobalConfig;
use RuntimeException;
use Throwable;

final readonly class EnsureAppProxyRoute
{
    public function __construct(
        private RemoteShell $remoteShell,
        private SiteCertificateInstaller $siteCertificateInstaller,
        private CaddyGlobalConfig $caddyGlobalConfig,
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
        $certificatePaths = $this->siteCertificateInstaller->expectedPathsFor($app->node, $domain);
        $config = [
            'document_root' => $app->documentRootPath(),
            'php_socket' => $this->socketPath($app),
            'tls' => [
                'cert_path' => $certificatePaths['cert'],
                'key_path' => $certificatePaths['key'],
            ],
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

        try {
            $this->siteCertificateInstaller->ensureFor($app->node, $domain);
            $this->ensureGlobalCaddyfile($app);
        } catch (Throwable) {
            return [[
                'code' => 'proxy.enactment_failed',
                'family' => 'proxy',
                'message' => "Proxy route '{$domain}' was recorded, but TLS material could not be installed. Run doctor to converge proxy artifacts.",
                'next_command' => 'doctor --fix --family=proxy --restore',
            ]];
        }

        $result = $this->remoteShell->run($app->node, $this->renderInstallScript($domain, $content));

        if ($result->successful()) {
            return $this->productionActivationWarnings($app);
        }

        return [[
            'code' => 'proxy.enactment_failed',
            'family' => 'proxy',
            'message' => "Proxy route '{$domain}' was recorded, but backend enactment failed. Run doctor to converge proxy artifacts.",
            'next_command' => 'doctor --fix --family=proxy --restore',
        ]];
    }

    /**
     * @return list<array<string, string>>
     */
    private function productionActivationWarnings(App $app): array
    {
        if (! is_string($app->domain) || $app->domain === '') {
            return [];
        }

        return [[
            'code' => 'proxy.domain_inactive',
            'family' => 'proxy',
            'message' => "Production domain '{$app->domain}' is not yet active. Retry with 'orbit app:register {$app->name} --domain={$app->domain}' once DNS has propagated.",
            'next_command' => "app:register {$app->name} --domain={$app->domain}",
        ]];
    }

    /**
     * @param  array{
     *     document_root: string,
     *     php_socket: string,
     *     tls: array{cert_path: string, key_path: string},
     * }  $config
     */
    private function renderCaddySite(App $app, string $domain, array $config): string
    {
        $pathBlocking = $app->document_root === '.'
            ? 'import path_blocking_project_root'
            : 'import path_blocking_public_root';

        return <<<CADDY
{$domain} {
    tls {$config['tls']['cert_path']} {$config['tls']['key_path']}
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

    private function ensureGlobalCaddyfile(App $app): void
    {
        if ($app->node === null) {
            throw new RuntimeException("App '{$app->name}' has no owning node.");
        }

        $readResult = $this->remoteShell->run(
            $app->node,
            'sudo test -f /etc/caddy/Caddyfile && sudo cat /etc/caddy/Caddyfile || true',
            ['throw' => true],
        );

        $updated = $this->caddyGlobalConfig->ensure($readResult->stdout);

        if ($updated === $readResult->stdout) {
            return;
        }

        $this->remoteShell->run(
            $app->node,
            sprintf(
                'printf %%s %s | base64 -d | sudo tee /etc/caddy/Caddyfile >/dev/null',
                escapeshellarg(base64_encode($updated)),
            ),
            ['throw' => true],
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
        $user = $app->node?->user ?: 'orbit';
        $home = $user === 'root' ? '/root' : "/home/{$user}";

        return "{$home}/.config/orbit/php/{$app->name}.sock";
    }
}
