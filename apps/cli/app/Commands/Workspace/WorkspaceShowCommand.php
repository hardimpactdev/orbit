<?php

declare(strict_types=1);

namespace App\Commands\Workspace;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

final class WorkspaceShowCommand extends GatewayCommand
{
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'workspace:show
        {name? : Workspace name}
        {--app= : Parent app slug}
        {--json}';

    #[\Override]
    protected $description = 'Show one workspace from the gateway registry.';

    public function handle(): int
    {
        $name = $this->stringArgument('name');

        if ($name === null) {
            return $this->showFromPath();
        }

        $path = '/api/workspaces/'.rawurlencode($name);

        try {
            $response = $this->gatewayGet($path, $this->filledQuery([
                'app' => $this->stringOption('app'),
            ]));
        } catch (GatewayApiException $exception) {
            return $this->renderFailure($exception->cliFailureCode(), $exception->getMessage());
        }

        return $this->renderSuccess($response);
    }

    private function showFromPath(): int
    {
        $hostCwd = $this->hostCwd();

        if ($hostCwd === null) {
            return $this->renderFailure('validation_failed', 'Workspace name is required.', ['field' => 'name']);
        }

        try {
            $response = $this->gatewayGet('/api/workspaces/resolve-by-path', $this->filledQuery([
                'app' => $this->stringOption('app'),
                'path' => $hostCwd,
            ]));
        } catch (GatewayApiException $exception) {
            return $this->renderFailure($exception->cliFailureCode(), $exception->getMessage());
        }

        return $this->renderSuccess($response);
    }
}
