<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Ui\Mcp\Tools;

use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Services\OrbitCli\ServiceControlService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class LogsTool extends Tool
{
    protected string $name = 'orbit_logs';

    protected string $description = 'Get service logs from Docker containers';

    public function __construct(protected ServiceControlService $serviceControl) {}

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'service' => $schema->string()->required()->description('Container name (e.g., orbit-php-83, orbit-redis, orbit-postgres). Note: Caddy runs on host via systemd, use journalctl for its logs.'),
            'lines' => $schema->integer()->default(100)->min(1)->max(1000)->description('Number of lines to retrieve (1-1000)'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $environment = Environment::getLocal();

        if (! $environment) {
            return Response::error('No local environment configured');
        }

        $service = $request->get('service');
        $lines = $request->get('lines', 100);

        if (! $service) {
            return Response::error('Service/container name is required');
        }

        // Validate lines
        if ($lines < 1 || $lines > 1000) {
            return Response::error('Lines must be between 1 and 1000');
        }

        $result = $this->serviceControl->serviceLogs($environment, $service, $lines);

        if (! $result['success']) {
            return Response::error($result['error'] ?? 'Failed to retrieve logs');
        }

        $logs = $result['logs'] ?? '';

        return Response::structured([
            'service' => $service,
            'lines_requested' => $lines,
            'logs' => $logs,
            'lines_returned' => substr_count($logs, "\n"),
        ]);
    }
}
