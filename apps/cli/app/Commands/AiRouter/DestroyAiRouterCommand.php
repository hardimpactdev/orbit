<?php

declare(strict_types=1);

namespace App\Commands\AiRouter;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\AiRouter\DestroyAiRouterRequest;
use Orbit\Sdk\Responses\AiRouter\AiRouterStatusResponse;

final class DestroyAiRouterCommand extends AiRouterCommand
{
    #[\Override]
    protected $signature = 'ai-router:destroy
        {--yes : Confirm the fleet destruction without a prompt}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Destroy the collector and withdraw collector.ai-router.orbit.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        if (! $this->confirmAction(
            'Destroy the AiRouter fleet feature? This stops the collector, withdraws collector.ai-router.orbit and its certificate, and deletes the stored management key and tokens.',
            'AiRouter destruction cancelled.',
            requiredMessage: 'Non-interactive AiRouter destruction requires --yes.',
        )) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $factory);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new DestroyAiRouterRequest,
            AiRouterStatusResponse::class,
            ['Destroy ai-router', 'Stopping the collector', 'Destroyed ai-router'],
        );

        return $response instanceof AiRouterStatusResponse
            ? $this->renderStatus($response, 'ai-router fleet feature is destroyed.')
            : self::FAILURE;
    }
}
