<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Mcp\Resources;

use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Services\OrbitCli\ConfigurationService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

class ConfigResource extends Resource
{
    protected string $uri = 'orbit://config';

    protected string $mimeType = 'application/json';

    public function __construct(protected ConfigurationService $configService) {}

    public function name(): string
    {
        return 'config';
    }

    public function title(): string
    {
        return 'Configuration';
    }

    public function description(): string
    {
        return 'Current Orbit configuration including TLD, default PHP version, paths, and enabled services.';
    }

    public function handle(Request $request): Response
    {
        $environment = Environment::getLocal();

        if (! $environment) {
            return Response::json([
                'error' => 'No local environment configured',
            ]);
        }

        $configResult = $this->configService->getConfig($environment);

        if (! $configResult['success']) {
            return Response::json([
                'error' => $configResult['error'] ?? 'Failed to get configuration',
            ]);
        }

        $config = $configResult['data'] ?? [];

        return Response::json([
            'tld' => $config['tld'] ?? 'test',
            'default_php_version' => $config['default_php_version'] ?? '8.4',
            'paths' => $config['paths'] ?? [],
            'available_php_versions' => $config['available_php_versions'] ?? ['8.3', '8.4', '8.5'],
            'enabled_services' => $config['enabled_services'] ?? [],
        ]);
    }
}
