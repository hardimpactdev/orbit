<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Mcp\Tools;

use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Services\OrbitCli\ConfigurationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class PhpTool extends Tool
{
    protected string $name = 'orbit_php';

    protected string $description = 'Get or set PHP version for a project';

    public function __construct(protected ConfigurationService $configService) {}

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'site' => $schema->string()->required()->description('The project name'),
            'action' => $schema->string()->enum(['get', 'set', 'reset'])->required()->description('Action to perform: get current version, set new version, or reset to default'),
            'version' => $schema->string()->enum(['8.3', '8.4', '8.5'])->description('PHP version to set (required for "set" action)'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $environment = Environment::getLocal();

        if (! $environment) {
            return Response::error('No local environment configured');
        }

        $site = $request->get('site');
        $action = $request->get('action');
        $version = $request->get('version');

        // Validate required parameters
        if (! $site || ! $action) {
            return Response::error('Missing required parameters: site and action are required');
        }

        switch ($action) {
            case 'get':
                return $this->getPhpVersion($environment, $site);

            case 'set':
                if (! $version) {
                    return Response::error('Version parameter is required for "set" action');
                }

                if (! in_array($version, ['8.3', '8.4', '8.5'])) {
                    return Response::error('Invalid PHP version. Must be one of: 8.3, 8.4, 8.5');
                }

                return $this->setPhpVersion($environment, $site, $version);

            case 'reset':
                return $this->resetPhpVersion($environment, $site);

            default:
                return Response::error('Invalid action. Must be one of: get, set, reset');
        }
    }

    protected function getPhpVersion(Environment $environment, string $site): Response|ResponseFactory
    {
        $result = $this->configService->php($environment, $site);

        if (! $result['success']) {
            return Response::error($result['error'] ?? 'Failed to get PHP version');
        }

        $configResult = $this->configService->getConfig($environment);
        $defaultVersion = $configResult['success'] ? ($configResult['data']['default_php_version'] ?? '8.4') : '8.4';
        $phpVersion = $result['data']['php_version'] ?? $defaultVersion;

        return Response::structured([
            'site' => $site,
            'php_version' => $phpVersion,
            'is_custom' => $phpVersion !== $defaultVersion,
            'default_version' => $defaultVersion,
        ]);
    }

    protected function setPhpVersion(Environment $environment, string $site, string $version): Response|ResponseFactory
    {
        $result = $this->configService->php($environment, $site, $version);

        if (! $result['success']) {
            return Response::error($result['error'] ?? 'Failed to set PHP version');
        }

        return Response::structured([
            'success' => true,
            'site' => $site,
            'php_version' => $version,
            'message' => "PHP version set to {$version} for {$site}",
        ]);
    }

    protected function resetPhpVersion(Environment $environment, string $site): Response|ResponseFactory
    {
        $result = $this->configService->phpReset($environment, $site);

        if (! $result['success']) {
            return Response::error($result['error'] ?? 'Failed to reset PHP version');
        }

        $configResult = $this->configService->getConfig($environment);
        $defaultVersion = $configResult['success'] ? ($configResult['data']['default_php_version'] ?? '8.4') : '8.4';

        return Response::structured([
            'success' => true,
            'site' => $site,
            'php_version' => $defaultVersion,
            'message' => "PHP version reset to default ({$defaultVersion}) for {$site}",
        ]);
    }
}
