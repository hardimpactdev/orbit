<?php

declare(strict_types=1);

namespace App\Commands\AiRouter;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\AiRouter\ShowAiRouterStatusRequest;
use Orbit\Sdk\Responses\AiRouter\AiRouterStatusResponse;

final class StatusAiRouterCommand extends AiRouterCommand
{
    #[\Override]
    protected $signature = 'ai-router:status {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show whether the fleet feature is enabled.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        $connector = $this->gatewayConnector($repository, $factory);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new ShowAiRouterStatusRequest,
            AiRouterStatusResponse::class,
            ['Show ai-router status', 'Loading ai-router status', 'Loaded ai-router status'],
        );

        return $response instanceof AiRouterStatusResponse
            ? $this->renderStatus($response, 'ai-router status.')
            : self::FAILURE;
    }
}
