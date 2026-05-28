<?php

declare(strict_types=1);

namespace App\Commands\Activity;

use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

final class ActivityListCommand extends GatewayCommand
{
    protected $signature = 'activity:list
        {--app= : Filter by app}
        {--node= : Filter by node}
        {--effect= : Filter by effect (read|write|destructive)}
        {--correlation= : Filter by correlation UUID}
        {--limit=25 : Max rows to return}
        {--json}';

    protected $description = 'List gateway activity history.';

    public function handle(): int
    {
        try {
            $response = $this->gatewayGet('/api/activity', array_filter([
                'app' => $this->option('app'),
                'node' => $this->option('node'),
                'effect' => $this->option('effect'),
                'correlation' => $this->option('correlation'),
                'limit' => $this->option('limit'),
            ], fn (mixed $v): bool => $v !== null));
        } catch (GatewayApiException $exception) {
            return $this->renderFailure($exception->cliFailureCode(), $exception->getMessage());
        }

        return $this->renderSuccess($response);
    }
}
