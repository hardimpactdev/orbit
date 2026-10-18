<?php

declare(strict_types=1);

namespace App\Commands\AiRouter;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\AiRouter\DisableAiRouterAccountRequest;
use Orbit\Sdk\Requests\AiRouter\EnableAiRouterAccountRequest;
use Orbit\Sdk\Responses\AiRouter\AiRouterAccountResponse;

abstract class AiRouterAccountCommand extends AiRouterCommand
{
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

        $response = $this->sendWithProgress(
            $connector,
            $this->enabled() ? new EnableAiRouterAccountRequest($account) : new DisableAiRouterAccountRequest($account),
            AiRouterAccountResponse::class,
            $this->enabled()
                ? ['Enable AI router account', 'Enabling account', 'Enabled AI router account']
                : ['Disable AI router account', 'Disabling account', 'Disabled AI router account'],
        );

        if (! $response instanceof AiRouterAccountResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail("Account [{$response->id}].", [
            'Account' => $response->id,
            'Provider' => $response->provider,
            'Label' => $response->label,
            'Disabled' => $response->disabled,
            'Status' => $response->status,
            'Error' => $response->error,
            'Request ID' => $response->requestId,
        ]));

        return self::SUCCESS;
    }
}
