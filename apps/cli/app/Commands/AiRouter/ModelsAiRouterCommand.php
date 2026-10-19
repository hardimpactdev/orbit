<?php

declare(strict_types=1);

namespace App\Commands\AiRouter;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\AiRouter\ListAiRouterModelsRequest;
use Orbit\Sdk\Responses\AiRouter\AiRouterModelResponse;
use Orbit\Sdk\Responses\AiRouter\AiRouterModelsResponse;

final class ModelsAiRouterCommand extends AiRouterCommand
{
    #[\Override]
    protected $signature = 'ai-router:model:list {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List models from the collector snapshot.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        $connector = $this->gatewayConnector($repository, $factory);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new ListAiRouterModelsRequest,
            AiRouterModelsResponse::class,
            ['List ai-router models', 'Loading models', 'Loaded models'],
        );

        if (! $response instanceof AiRouterModelsResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->table(
            ['Model', 'Provider'],
            array_map(static fn (AiRouterModelResponse $model): array => [
                $model->id,
                $model->provider,
            ], $response->models),
            'No models in the snapshot.',
        ));
        $this->writeHumanMessage("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
