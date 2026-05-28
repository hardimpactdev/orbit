<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

final class AppListCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'app:list
        {--node= : Filter by owning node}
        {--environment= : Filter by environment (development|production)}
        {--json}';

    #[\Override]
    protected $description = 'List apps registered in the gateway registry.';

    public function handle(): int
    {
        try {
            $response = $this->gatewayGet('/api/apps', array_filter([
                'node' => $this->option('node'),
                'environment' => $this->option('environment'),
            ], fn (mixed $v): bool => $v !== null));
        } catch (GatewayApiException $exception) {
            return $this->renderFailure($exception->cliFailureCode(), $exception->getMessage());
        }

        return $this->renderSuccess($response);
    }
}
