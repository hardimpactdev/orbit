<?php

declare(strict_types=1);

namespace App\Commands\Workspace;

use App\Exceptions\GatewayApiException;

final class WorkspaceSetupCommand extends WorkspaceGatewayCommand
{
    protected $signature = 'workspace:setup
        {name? : Workspace name}
        {--app= : Parent app name}
        {--path= : Explicit workspace path to adopt}
        {--json : Output JSON}';

    protected $description = 'Converge a workspace to a ready-to-develop-in state.';

    public function handle(): int
    {
        $path = $this->stringOption('path');

        if ($path !== null && ! str_starts_with($path, '/')) {
            return $this->failValidation('path', 'Path must be absolute.');
        }

        try {
            $response = $this->gatewayPost('/api/workspaces/setup', [
                'name' => $this->stringArgument('name'),
                'app' => $this->stringOption('app') ?? $this->appFromOrbitMarker(),
                'path' => $path,
                'caller_cwd' => $this->hostCwd(),
            ]);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }
}
