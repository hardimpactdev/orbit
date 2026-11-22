<?php

declare(strict_types=1);

namespace App\Commands\Conn;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\GatedExtensionCommand;
use Orbit\Sdk\Requests\Conn\UnregisterConnEnvironmentRequest;
use Orbit\Sdk\Responses\Conn\UnregisteredConnEnvironmentResponse;

final class UnregisterConnEnvironmentCommand extends GatewayCommand implements GatedExtensionCommand
{
    #[\Override]
    protected $signature = 'conn:environment:unregister
        {environment : Conn environment ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Unregister a host T3 server from the Conn registry';

    public function extensionSlug(): string
    {
        return 'conn';
    }

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $environmentId = trim((string) $this->argument('environment'));
        if ($environmentId === '') {
            $this->renderGatewayFailure('conn.environment_id_invalid', 'Environment ID must not be empty.');

            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->send(
            $connector,
            new UnregisterConnEnvironmentRequest($environmentId),
            UnregisteredConnEnvironmentResponse::class,
        );

        if (! $response instanceof UnregisteredConnEnvironmentResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson([
                'environment_id' => $response->environmentId,
                'removed' => $response->removed,
            ]);

            return self::SUCCESS;
        }

        $this->writeHumanMessage(
            $response->removed
                ? "Unregistered Conn environment {$response->environmentId}."
                : "Conn environment {$response->environmentId} was not registered.",
        );

        return self::SUCCESS;
    }
}
