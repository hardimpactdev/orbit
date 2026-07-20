<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Models\Node;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\Nodes\NodeHostPaths;
use App\Services\Workspaces\WorkspacePlacement;
use RuntimeException;

final readonly class LaravelViteDevServerEnvironment
{
    private const string CONTAINER_CERTIFICATE_DIRECTORY = '/etc/orbit/certs';

    public function __construct(
        private WorkspacePlacement $placement = new WorkspacePlacement,
    ) {}

    /**
     * @return array{
     *     APP_URL: string,
     *     VITE_APP_URL: string,
     *     VITE_VALET_HOST: string,
     *     VITE_DEV_SERVER_KEY: string,
     *     VITE_DEV_SERVER_CERT: string,
     * }
     */
    public function shellVariables(Project $app, Node $node, ?Workspace $workspace = null): array
    {
        $url = $this->url($app, $workspace);
        $host = $this->host($app, $workspace);
        $certificates = $this->hostCertificatePaths($node, $host);

        return [
            'APP_URL' => $url,
            'VITE_APP_URL' => $url,
            'VITE_VALET_HOST' => $host,
            'VITE_DEV_SERVER_KEY' => $certificates['key'],
            'VITE_DEV_SERVER_CERT' => $certificates['cert'],
        ];
    }

    /**
     * @return array{
     *     APP_URL: string,
     *     VITE_APP_URL: string,
     *     VITE_VALET_HOST: string,
     *     VITE_DEV_SERVER_KEY: string,
     *     VITE_DEV_SERVER_CERT: string,
     * }
     */
    public function containerVariables(Project $app, ?Workspace $workspace = null): array
    {
        $url = $this->url($app, $workspace);
        $host = $this->host($app, $workspace);
        $certificates = $this->containerCertificatePaths($host);

        return [
            'APP_URL' => $url,
            'VITE_APP_URL' => $url,
            'VITE_VALET_HOST' => $host,
            'VITE_DEV_SERVER_KEY' => $certificates['key'],
            'VITE_DEV_SERVER_CERT' => $certificates['cert'],
        ];
    }

    /**
     * @return list<array{source: string, target: string, read_only: bool}>
     */
    public function containerCertificateMounts(Project $app, ?Workspace $workspace = null): array
    {
        $app->loadMissing('node');
        $node = $workspace instanceof Workspace ? $this->placement->nodeForWorkspace($workspace) : $app->node;

        if (! $node instanceof Node) {
            throw new RuntimeException("App '{$app->name}' has no owning node.");
        }

        $host = $this->host($app, $workspace);
        $hostCertificates = $this->hostCertificatePaths($node, $host);
        $containerCertificates = $this->containerCertificatePaths($host);

        return [
            [
                'source' => $hostCertificates['key'],
                'target' => $containerCertificates['key'],
                'read_only' => true,
            ],
            [
                'source' => $hostCertificates['cert'],
                'target' => $containerCertificates['cert'],
                'read_only' => true,
            ],
        ];
    }

    public function url(Project $app, ?Workspace $workspace = null): string
    {
        return $workspace instanceof Workspace ? $workspace->url() : $app->url();
    }

    public function host(Project $app, ?Workspace $workspace = null): string
    {
        $url = $this->url($app, $workspace);
        $host = parse_url($url, PHP_URL_HOST);

        if (is_string($host)) {
            return $host;
        }

        $stripped = preg_replace(pattern: '#^https?://#', replacement: '', subject: $url) ?? '';

        if ($stripped !== '') {
            $host = explode('/', $stripped)[0];

            return $host !== '' ? $host : $this->fallbackHost($app, $workspace);
        }

        return $this->fallbackHost($app, $workspace);
    }

    private function fallbackHost(Project $app, ?Workspace $workspace): string
    {
        return $workspace instanceof Workspace ? "{$workspace->name}.{$app->name}" : $app->name;
    }

    /**
     * @return array{key: string, cert: string}
     */
    private function hostCertificatePaths(Node $node, string $host): array
    {
        $base = $this->hostHome($node).'/.config/orbit/certs/'.$host;

        return [
            'key' => $base.'.key',
            'cert' => $base.'.crt',
        ];
    }

    /**
     * @return array{key: string, cert: string}
     */
    private function containerCertificatePaths(string $host): array
    {
        $base = self::CONTAINER_CERTIFICATE_DIRECTORY.'/'.$host;

        return [
            'key' => $base.'.key',
            'cert' => $base.'.crt',
        ];
    }

    private function hostHome(Node $node): string
    {
        return NodeHostPaths::homeDirectoryFor($node->platform, $node->user);
    }
}
