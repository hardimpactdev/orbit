<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Mcp\Resources;

use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Services\OrbitCli\StatusService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

class InfrastructureResource extends Resource
{
    protected string $uri = 'orbit://infrastructure';

    protected string $mimeType = 'application/json';

    public function __construct(protected StatusService $statusService) {}

    public function name(): string
    {
        return 'infrastructure';
    }

    public function title(): string
    {
        return 'Infrastructure';
    }

    public function description(): string
    {
        return 'All running services with their status, health, and ports. Caddy/PHP-FPM run on the host; Docker runs dns, reverb, and postgres.';
    }

    public function handle(Request $request): Response
    {
        $environment = Environment::getLocal();

        if (! $environment) {
            return Response::json([
                'error' => 'No local environment configured',
            ]);
        }

        $statusResult = $this->statusService->status($environment);

        if (! $statusResult['success']) {
            return Response::json([
                'error' => $statusResult['error'] ?? 'Failed to get status',
            ]);
        }

        $data = $statusResult['data'] ?? [];
        $services = $data['services'] ?? [];

        // Count running and healthy services
        $runningCount = 0;
        $healthyCount = 0;
        $formattedServices = [];

        foreach ($services as $name => $service) {
            $isRunning = ($service['status'] ?? '') === 'running';
            $health = $service['health'] ?? null;

            $formattedServices[$name] = [
                'status' => $service['status'] ?? 'unknown',
                'health' => $health,
                'type' => $service['type'] ?? 'docker',
                'container' => $service['container'] ?? null,
                'ports' => $this->getContainerPorts($name),
            ];

            if ($isRunning) {
                $runningCount++;
                if ($health === 'healthy' || $health === null) {
                    $healthyCount++;
                }
            }
        }

        return Response::json([
            'architecture' => $data['architecture'] ?? 'unknown',
            'services' => $formattedServices,
            'summary' => [
                'total' => count($services),
                'running' => $runningCount,
                'healthy' => $healthyCount,
                'stopped' => count($services) - $runningCount,
            ],
        ]);
    }

    protected function getContainerPorts(string $container): array
    {
        $portsMap = [
            'dns' => ['53/udp', '53/tcp'],
            'caddy' => ['80/tcp', '443/tcp'],
            'postgres' => ['5432/tcp'],
            'redis' => ['6379/tcp'],
            'mailpit' => ['1025/tcp', '8025/tcp'],
            'reverb' => ['8080/tcp'],
            'horizon' => [],
            'php-fpm' => [],
        ];

        return $portsMap[$container] ?? [];
    }
}
