<?php

declare(strict_types=1);

namespace App\Commands\AiRouter;

use App\Commands\Concerns\FollowsOperations;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\AiRouter\DisableAiRouterAccountRequest;
use Orbit\Sdk\Requests\AiRouter\EnableAiRouterAccountRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

abstract class AiRouterAccountCommand extends AiRouterCommand
{
    use FollowsOperations;

    abstract protected function enabled(): bool;

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        $account = $this->stringArgument('account', 'Account identity', 'ai_router.account_required');

        if ($account === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $factory);

        if ($connector === null) {
            return self::FAILURE;
        }

        $profile = $this->activeGatewayProfile($repository);
        if ($profile === null) {
            return self::FAILURE;
        }
        $operation = $this->send($connector,
            $this->enabled() ? new EnableAiRouterAccountRequest($account) : new DisableAiRouterAccountRequest($account),
            OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation,
                ($this->enabled() ? 'Enable' : 'Disable')." AI router account [{$account}]", 'Account update')
            : self::FAILURE;
    }
}
