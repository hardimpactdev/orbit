<?php

declare(strict_types=1);

namespace App\Commands\Process;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

final class ProcessListCommand extends GatewayCommand
{
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'process:list
        {--node= : Owning node name}
        {--app= : Parent app slug}
        {--workspace= : Workspace name}
        {--json}';

    #[\Override]
    protected $description = 'List configured processes.';

    public function handle(): int
    {
        $node = $this->stringOption('node');
        $app = $node === null ? $this->stringOption('app') ?? $this->appFromOrbitMarker() : $this->stringOption('app');
        $workspace = $this->stringOption('workspace');

        if ($node !== null && ($app !== null || $workspace !== null)) {
            return $this->renderFailure('validation_failed', 'A node context cannot be combined with app or workspace context.', [
                'field' => 'context',
                'node' => $node,
                'app' => $app,
                'workspace' => $workspace,
            ]);
        }

        try {
            $response = $this->gatewayGet('/api/processes', $this->filledQuery([
                'node' => $node,
                'app' => $app,
                'workspace' => $workspace,
            ]));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }
}
