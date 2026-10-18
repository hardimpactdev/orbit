<?php

declare(strict_types=1);

namespace App\Commands\AiRouter;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\AiRouter\ListAiRouterProvidersRequest;
use Orbit\Sdk\Responses\AiRouter\AiRouterProviderResponse;
use Orbit\Sdk\Responses\AiRouter\AiRouterProvidersResponse;
use Orbit\Sdk\Responses\AiRouter\AiRouterWindowResponse;

final class ListAiRouterCommand extends AiRouterCommand
{
    #[\Override]
    protected $signature = 'ai-router:provider:list {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List provider pools from the Valkey snapshot.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        $connector = $this->gatewayConnector($repository, $factory);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new ListAiRouterProvidersRequest,
            AiRouterProvidersResponse::class,
            ['List ai-router providers', 'Loading provider pools', 'Loaded provider pools'],
        );

        if (! $response instanceof AiRouterProvidersResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->table(
            ['Provider', 'Windows', 'Accounts'],
            array_map(static fn (AiRouterProviderResponse $provider): array => [
                $provider->provider,
                implode(', ', array_map(
                    static fn (AiRouterWindowResponse $window): string => sprintf(
                        '%s %s%% remaining',
                        $window->label,
                        rtrim(rtrim(number_format($window->remainingPercent, 1), '0'), '.'),
                    ),
                    $provider->windows,
                )),
                (string) count($provider->accounts),
            ], $response->providers),
            'No provider pools in the snapshot.',
        ));
        $this->writeHumanMessage("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
