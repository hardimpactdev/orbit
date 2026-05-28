<?php

declare(strict_types=1);

namespace App\Commands\Workspace;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

final class WorkspaceTeardownStepListCommand extends GatewayCommand
{
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'workspace-teardown-step:list
        {--app= : Parent app slug}
        {--json}';

    #[\Override]
    protected $description = 'List workspace teardown steps for an app.';

    public function handle(): int
    {
        try {
            $response = $this->gatewayGet('/api/workspaces/steps/teardown', $this->stepQuery());
        } catch (GatewayApiException $exception) {
            return $this->renderFailure($exception->cliFailureCode(), $exception->getMessage());
        }

        return $this->renderSuccess($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function stepQuery(): array
    {
        $app = $this->stringOption('app') ?? $this->appFromOrbitMarker();

        if ($app !== null) {
            return ['app' => $app];
        }

        $hostCwd = $this->hostCwd();

        return $hostCwd === null ? [] : ['path' => $hostCwd];
    }
}
