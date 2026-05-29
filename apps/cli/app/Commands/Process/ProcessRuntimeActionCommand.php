<?php

declare(strict_types=1);

namespace App\Commands\Process;

use App\Exceptions\GatewayApiException;

abstract class ProcessRuntimeActionCommand extends ProcessGatewayCommand
{
    abstract protected function action(): string;

    public function handle(): int
    {
        $app = $this->appContext();
        $workspace = $this->stringOption('workspace');

        if ($app === null && $workspace === null) {
            return $this->failValidation('app', 'An app context is required.');
        }

        try {
            $response = $this->gatewayPost("/api/processes/{$this->action()}", $this->filledQuery([
                'app' => $app,
                'workspace' => $workspace,
                'name' => $this->stringArgument('name'),
            ]));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }
}
