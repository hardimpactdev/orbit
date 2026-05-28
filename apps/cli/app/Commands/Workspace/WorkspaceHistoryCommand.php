<?php

declare(strict_types=1);

namespace App\Commands\Workspace;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

final class WorkspaceHistoryCommand extends GatewayCommand
{
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'workspace:history
        {name? : Workspace name}
        {--app= : Parent app slug}
        {--limit= : Maximum number of runs to return}
        {--since= : ISO 8601 lower started_at bound}
        {--until= : ISO 8601 exclusive upper started_at bound}
        {--json}';

    #[\Override]
    protected $description = 'Show workspace run history.';

    public function handle(): int
    {
        $name = $this->stringArgument('name');

        if ($name === null) {
            return $this->historyFromPath();
        }

        $path = '/api/workspaces/'.rawurlencode($name).'/history';

        try {
            $response = $this->gatewayGet($path, $this->historyQuery());
        } catch (GatewayApiException $exception) {
            return $this->renderFailure($exception->cliFailureCode(), $exception->getMessage());
        }

        return $this->renderSuccess($response);
    }

    private function historyFromPath(): int
    {
        $hostCwd = $this->hostCwd();

        if ($hostCwd === null) {
            return $this->renderFailure('validation_failed', 'Workspace name is required.', ['field' => 'name']);
        }

        try {
            $response = $this->gatewayGet('/api/workspaces/history/resolve-by-path', [
                ...$this->historyQuery(),
                'path' => $hostCwd,
            ]);
        } catch (GatewayApiException $exception) {
            return $this->renderFailure($exception->cliFailureCode(), $exception->getMessage());
        }

        return $this->renderSuccess($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function historyQuery(): array
    {
        return $this->filledQuery([
            'app' => $this->stringOption('app'),
            'limit' => $this->stringOption('limit'),
            'since' => $this->stringOption('since'),
            'until' => $this->stringOption('until'),
        ]);
    }
}
