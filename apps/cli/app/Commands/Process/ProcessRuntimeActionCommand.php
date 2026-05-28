<?php

declare(strict_types=1);

namespace App\Commands\Process;

use App\Exceptions\GatewayApiException;

abstract class ProcessRuntimeActionCommand extends ProcessGatewayCommand
{
    abstract protected function action(): string;

    public function handle(): int
    {
        try {
            $response = $this->gatewayPost("/api/processes/{$this->action()}", $this->filledQuery([
                'app' => $this->appContext(),
                'workspace' => $this->stringOption('workspace'),
                'name' => $this->stringArgument('name'),
            ]));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }
}
