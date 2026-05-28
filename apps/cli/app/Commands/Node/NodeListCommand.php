<?php

declare(strict_types=1);

namespace App\Commands\Node;

use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

final class NodeListCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'node:list
        {--role= : Filter by role}
        {--json}';

    #[\Override]
    protected $description = 'List nodes registered in the gateway registry.';

    public function handle(): int
    {
        try {
            $response = $this->gatewayGet('/api/nodes', array_filter([
                'role' => $this->option('role'),
            ], fn (mixed $v): bool => $v !== null));
        } catch (GatewayApiException $exception) {
            return $this->renderFailure($exception->cliFailureCode(), $exception->getMessage());
        }

        return $this->renderSuccess($response);
    }
}
