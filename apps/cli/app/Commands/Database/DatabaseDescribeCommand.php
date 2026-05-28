<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

final class DatabaseDescribeCommand extends GatewayCommand
{
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'database:describe
        {target? : App, workspace, or connection slug}
        {table? : Table name}
        {--connection= : Connection slug when the target maps to multiple connections}
        {--json}';

    #[\Override]
    protected $description = 'Describe a table for a registered database connection.';

    public function handle(): int
    {
        $target = $this->stringArgument('target');
        $table = $this->stringArgument('table');

        if ($target === null) {
            return $this->renderFailure('validation_failed', 'The target argument is required.', ['field' => 'target']);
        }

        if ($table === null) {
            return $this->renderFailure('validation_failed', 'The table argument is required.', ['field' => 'table']);
        }

        try {
            $response = $this->gatewayGet('/api/database-connections/describe', $this->filledQuery([
                'target' => $target,
                'table' => $table,
                'connection' => $this->stringOption('connection'),
            ]));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }
}
