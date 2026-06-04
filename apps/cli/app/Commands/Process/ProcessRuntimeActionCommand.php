<?php

declare(strict_types=1);

namespace App\Commands\Process;

use App\Exceptions\GatewayApiException;

abstract class ProcessRuntimeActionCommand extends ProcessGatewayCommand
{
    abstract protected function action(): string;

    public function handle(): int
    {
        $node = $this->nodeContext();
        $app = $node === null ? $this->appContext() : $this->stringOption('app');
        $workspace = $this->workspaceContext();

        if ($node !== null && ($app !== null || $workspace !== null)) {
            return $this->failValidation('context', 'A node context cannot be combined with app or workspace context.', [
                'node' => $node,
                'app' => $app,
                'workspace' => $workspace,
            ]);
        }

        if ($node === null && $app === null && $workspace === null) {
            return $this->failValidation('app', 'A node, app, or workspace context is required.');
        }

        try {
            $response = $this->gatewayPost("/api/processes/{$this->action()}", $this->filledQuery([
                'node' => $node,
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
