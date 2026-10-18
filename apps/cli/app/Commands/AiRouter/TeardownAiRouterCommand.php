<?php

declare(strict_types=1);

namespace App\Commands\AiRouter;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\AiRouter\TeardownAiRouterRequest;
use Orbit\Sdk\Responses\AiRouter\AiRouterStatusResponse;

final class TeardownAiRouterCommand extends AiRouterCommand
{
    #[\Override]
    protected $signature = 'ai-router:teardown
        {--yes : Confirm the fleet teardown without a prompt}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Tear down the collector and withdraw collector.ai-router.orbit.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        if (! $this->confirmAction(
            'Tear down the AiRouter fleet feature? This stops the collector, withdraws collector.ai-router.orbit and its certificate, and deletes the stored management key and tokens.',
            'AiRouter teardown cancelled.',
            requiredMessage: 'Non-interactive AiRouter teardown requires --yes.',
        )) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $factory);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new TeardownAiRouterRequest,
            AiRouterStatusResponse::class,
            ['Teardown ai-router', 'Stopping the collector', 'Tore down ai-router'],
        );

        return $response instanceof AiRouterStatusResponse
            ? $this->renderStatus($response, 'ai-router fleet feature is torn down.')
            : self::FAILURE;
    }
}
