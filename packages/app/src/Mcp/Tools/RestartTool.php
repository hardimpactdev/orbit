<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Mcp\Tools;

use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Services\OrbitCli\ServiceControlService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

final class RestartTool extends Tool
{
    protected string $name = 'orbit_restart';

    protected string $description = 'Restart all Orbit Docker services (stops all services, then starts them again)';

    public function __construct(protected ServiceControlService $serviceControl) {}

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

        try {
            $result = $this->serviceControl->restart($environment);

            if (! $result['success']) {
                return Response::structured([
                    'success' => false,
                    'error' => $result['error'] ?? 'Failed to restart services',
                ]);
            }

            return Response::structured([
                'success' => true,
                'message' => 'All Orbit services restarted successfully',
            ]);
        } catch (\Exception $e) {
            return Response::structured([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
