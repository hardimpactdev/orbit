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

final class StartTool extends Tool
{
    protected string $name = 'orbit_start';

    protected string $description = 'Start all Orbit Docker services (DNS, PHP, Caddy, PostgreSQL, Redis, Mailpit, and enabled optional services)';

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
            $result = $this->serviceControl->start($environment);

            if (! $result['success']) {
                return Response::structured([
                    'success' => false,
                    'error' => $result['error'] ?? 'Failed to start services',
                ]);
            }

            return Response::structured([
                'success' => true,
                'message' => 'All Orbit services started successfully',
            ]);
        } catch (\Exception $e) {
            return Response::structured([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
