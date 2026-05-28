<?php

declare(strict_types=1);

namespace App\Commands\Node;

use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

final class NodeRevokeCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'node:revoke
        {consuming_node? : Name of the node whose access is being revoked}
        {serving_node? : Name of the node providing access}
        {--force : Confirm destructive operation without prompting}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Revoke one node\'s access to another.';

    public function handle(): int
    {
        $consumingNode = $this->stringArgument('consuming_node');
        $servingNode = $this->stringArgument('serving_node');

        if ($consumingNode === null) {
            return $this->renderFailure('validation_failed', 'Consuming node is required.', ['field' => 'consuming_node']);
        }

        if ($servingNode === null) {
            return $this->renderFailure('validation_failed', 'Serving node is required.', ['field' => 'serving_node']);
        }

        if (! (bool) $this->option('force')) {
            return $this->renderFailure('validation_failed', 'Use --force to revoke this grant.', ['field' => 'force']);
        }

        try {
            $response = $this->gatewayPost('/api/nodes/revoke', [
                'consuming_node' => $consumingNode,
                'serving_node' => $servingNode,
                'force' => true,
            ]);
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }

    private function stringArgument(string $key): ?string
    {
        $value = $this->argument($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
