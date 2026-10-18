<?php

declare(strict_types=1);

namespace App\Commands\AiRouter;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\AiRouter\EnableAiRouterAccountRequest;
use Orbit\Sdk\Responses\AiRouter\AiRouterAccountResponse;

final class EnableAiRouterAccountCommand extends AiRouterCommand
{
    #[\Override]
    protected $signature = 'ai-router:account:enable
        {account : Auth file name or auth_index}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Enable one CLIProxyAPI account.';

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
            new EnableAiRouterAccountRequest($account),
            AiRouterAccountResponse::class,
            ['Enable AI router account', 'Enabling account', 'Enabled AI router account'],
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
