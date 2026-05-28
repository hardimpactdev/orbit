<?php

declare(strict_types=1);

namespace App\Commands\Process;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

final class ProcessListCommand extends GatewayCommand
{
    use ResolvesHostContext;

    protected $signature = 'process:list
        {--app= : Parent app slug}
        {--workspace= : Workspace name}
        {--json}';

    protected $description = 'List configured app processes.';

    public function handle(): int
    {
        try {
            $response = $this->gatewayGet('/api/processes', $this->filledQuery([
                'app' => $this->stringOption('app') ?? $this->appFromOrbitMarker(),
                'workspace' => $this->stringOption('workspace'),
            ]));
        } catch (GatewayApiException $exception) {
            return $this->renderFailure($exception->cliFailureCode(), $exception->getMessage());
        }

        return $this->renderSuccess($response);
    }
}
