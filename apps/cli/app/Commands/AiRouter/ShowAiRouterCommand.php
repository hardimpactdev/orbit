<?php

declare(strict_types=1);

namespace App\Commands\AiRouter;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\AiRouter\ShowAiRouterProviderRequest;
use Orbit\Sdk\Responses\AiRouter\AiRouterAccountResponse;
use Orbit\Sdk\Responses\AiRouter\AiRouterProviderResponse;
use Orbit\Sdk\Responses\AiRouter\AiRouterWindowResponse;

final class ShowAiRouterCommand extends AiRouterCommand
{
    #[\Override]
    protected $signature = 'ai-router:provider:show
        {provider : Provider slug such as codex or claude}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show one provider and its accounts.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        $provider = $this->stringArgument('provider', 'Provider slug', 'ai_router.provider_required');

        if ($provider === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $factory);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new ShowAiRouterProviderRequest($provider),
            AiRouterProviderResponse::class,
            ['Show ai-router provider', 'Loading provider pool', 'Loaded provider pool'],
        );

        if (! $response instanceof AiRouterProviderResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail("Provider [{$response->provider}].", [
            'Provider' => $response->provider,
            'Windows' => implode(', ', array_map(
                static fn (AiRouterWindowResponse $window): string => $window->label,
                $response->windows,
            )),
            'Request ID' => $response->requestId,
        ]));
        ConsoleWriter::write($this->output, $this->humanRenderer()->table(
            ['Account', 'State', 'Windows', 'Resets'],
            array_map(static fn (AiRouterAccountResponse $account): array => [
                $account->id,
                $account->disabled ? 'disabled' : ($account->status ?? 'ok'),
                implode(', ', array_map(
                    static fn (AiRouterWindowResponse $window): string => sprintf(
                        '%s %s%% remaining',
                        $window->label,
                        rtrim(rtrim(number_format($window->remainingPercent, 1), '0'), '.'),
                    ),
                    $account->windows,
                )),
                implode(', ', array_values(array_filter(array_map(
                    static fn (AiRouterWindowResponse $window): ?string => $window->resetsAt === null
                        ? null
                        : "{$window->label} {$window->resetsAt}",
                    $account->windows,
                )))),
            ], $response->accounts),
            'No accounts in this pool.',
        ));

        return self::SUCCESS;
    }
}
