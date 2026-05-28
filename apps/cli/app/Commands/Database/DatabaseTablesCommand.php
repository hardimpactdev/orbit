<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

final class DatabaseTablesCommand extends GatewayCommand
{
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'database:tables
        {target? : App, workspace, or connection slug}
        {--connection= : Connection slug when the target maps to multiple connections}
        {--json}';

    #[\Override]
    protected $description = 'List tables for a registered database connection.';

    public function handle(): int
    {
        $target = $this->stringArgument('target');

        if ($target === null) {
            return $this->renderFailure('validation_failed', 'The target argument is required.', ['field' => 'target']);
        }

        try {
            $response = $this->gatewayGet('/api/database-connections/tables', $this->filledQuery([
                'target' => $target,
                'connection' => $this->stringOption('connection'),
            ]));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }
}
