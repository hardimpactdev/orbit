<?php

declare(strict_types=1);

namespace App\Commands\AiRouter;

use App\Commands\GatewayCommand;
use App\Support\Console\ConsoleWriter;
use App\Support\GatedExtensionCommand;
use Orbit\Sdk\Responses\AiRouter\AiRouterStatusResponse;

abstract class AiRouterCommand extends GatewayCommand implements GatedExtensionCommand
{
    public function extensionSlug(): ?string
    {
        return 'ai-router';
    }

    protected function renderStatus(AiRouterStatusResponse $response, string $message): int
    {
        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail($message, [
            'Enabled' => $response->enabled,
            'Cutover pending' => $response->cutoverPending,
            'Hostname' => $response->hostname,
            'Node ID' => $response->nodeId,
            'Cache connection' => $response->cacheConnection,
            'Collected at' => $response->collectedAt,
            'Request ID' => $response->requestId,
        ]));

        return self::SUCCESS;
    }
}
