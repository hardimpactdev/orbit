<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Support\Console\ConsoleWriter;
use Laravel\Prompts\TextPrompt;
use Orbit\Sdk\Responses\Instances\InstancePathProxyResponse;

abstract class InstancePathProxyCommand extends GatewayCommand
{
    protected function proxyInput(string $option, string $label): ?string
    {
        $value = $this->stringOption($option);
        if ($value === null && $this->consoleMode()->mayPrompt) {
            $value = $this->commandPrompts()->run(fn (): TextPrompt => new TextPrompt(label: $label, required: true));
        }
        if (! is_string($value) || $value === '') {
            $this->renderGatewayFailure('path_proxy.input_required', $label.' is required.');

            return null;
        }

        return $value;
    }

    protected function renderProxy(InstancePathProxyResponse $proxy, string $message): int
    {
        if ($this->option('json') === true) {
            $this->writeJson($proxy->toArray());
        } else {
            ConsoleWriter::write($this->output, $this->humanRenderer()->detail($message, [
                'Instance ID' => $proxy->instanceId, 'Route ID' => $proxy->routeId, 'Process ID' => $proxy->processId,
                'Port' => $proxy->port, 'Prefix' => $proxy->prefix, 'Endpoint' => $proxy->endpoint,
                'Enabled' => $proxy->enabled ? 'true' : 'false', 'Request ID' => $proxy->requestId,
            ]));
        }

        return self::SUCCESS;
    }
}
