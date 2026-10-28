<?php

declare(strict_types=1);

namespace App\Commands\Routes;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Routes\ShowRouteRequest;
use Orbit\Sdk\Requests\Routes\UnsetRouteTargetRequest;
use Orbit\Sdk\Responses\Routes\RouteResponse;

final class UnsetRouteTargetCommand extends RouteCommand
{
    #[\Override]
    protected $signature = 'route:target:unset {route : Numeric Route ID} {--yes : Confirm target clearing without prompting}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Clear the configured Route target.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $id = $this->routeId();
        if ($id === null) {
            return self::FAILURE;
        }
        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }
        if ($this->option('yes') !== true && $this->option('idempotency-key') === null) {
            $existing = $this->sendWithProgress(
                $connector,
                new ShowRouteRequest($id),
                RouteResponse::class,
                ['Inspect Route', 'Inspecting Route', 'Inspected Route'],
            );

            if (! $existing instanceof RouteResponse || ! $this->confirmAction(
                "Confirm Route target clearing [{$existing->domain}]?",
                'Route target clearing cancelled.',
            )) {
                return self::FAILURE;
            }
        }

        return $this->startRouteOperation($repository, $connector, new UnsetRouteTargetRequest($id), 'Clear Route target');
    }
}
