<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Mcp\Tools;

use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Services\OrbitCli\StatusService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class StatusTool extends Tool
{
    protected string $name = 'orbit_status';

    protected string $description = 'Get Orbit service status including running containers, sites count, TLD, and default PHP version';

    public function __construct(protected StatusService $statusService) {}

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): ResponseFactory
    {
        $environment = Environment::getLocal();

        if (! $environment) {
            return Response::structured([
                'success' => false,
                'error' => 'No local environment configured',
            ]);
        }

        $result = $this->statusService->status($environment);

        if (! $result['success']) {
            return Response::structured([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to get status',
            ]);
        }

        $data = $result['data'] ?? [];
        $services = $data['services'] ?? [];

        $runningCount = count(array_filter($services, fn ($s) => ($s['status'] ?? '') === 'running'));
        $totalCount = count($services);
        $running = $runningCount === $totalCount && $totalCount > 0;

        return Response::structured([
            'running' => $running,
            'architecture' => $data['architecture'] ?? 'unknown',
            'services' => $services,
            'services_running' => $runningCount,
            'services_total' => $totalCount,
            'sites_count' => $data['sites_count'] ?? 0,
            'tld' => $data['tld'] ?? 'test',
            'default_php_version' => $data['default_php_version'] ?? '8.4',
        ]);
    }
}
