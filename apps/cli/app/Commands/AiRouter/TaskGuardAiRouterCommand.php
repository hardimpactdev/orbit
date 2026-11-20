<?php

declare(strict_types=1);

namespace App\Commands\AiRouter;

use App\Commands\Concerns\FollowsOperations;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\AiRouter\AiRouterTaskGuardRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class TaskGuardAiRouterCommand extends AiRouterCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'ai-router:task-guard
        {--remove : Take the guard, its keys and its rules off the router\'s Node}
        {--yes : Confirm the removal without a prompt}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Apply, or with --remove withdraw, the task VM guard of the model router: its Caddy, its keys and its UFW rules.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        $remove = $this->option('remove') === true;
        if ($remove && ! $this->confirmAction(
            'Remove the model router guard? Task VMs and containers lose model access until the guard is applied again.',
            'Guard removal cancelled.',
            requiredMessage: 'Non-interactive guard removal requires --yes.',
        )) {
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
        $key = $this->stringOption('idempotency-key') ?? bin2hex(random_bytes(16));
        $operation = $this->send($connector, new AiRouterTaskGuardRequest($remove, $key), OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, $remove ? 'Remove the model router guard' : 'Apply the model router guard', 'Guard')
            : self::FAILURE;
    }
}
