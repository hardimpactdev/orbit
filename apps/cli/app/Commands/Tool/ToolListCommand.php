<?php

declare(strict_types=1);

namespace App\Commands\Tool;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

final class ToolListCommand extends GatewayCommand
{
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'tool:list
        {--app= : Filter by app selector}
        {--node= : Filter by owning node}
        {--json}';

    #[\Override]
    protected $description = 'List tool state tracked by the gateway registry.';

    public function handle(): int
    {
        try {
            $response = $this->gatewayGet('/api/tools', $this->filledQuery([
                'app' => $this->stringOption('app'),
                'node' => $this->stringOption('node'),
            ]));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }
}
