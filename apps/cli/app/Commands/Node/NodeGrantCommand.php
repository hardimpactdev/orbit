<?php

declare(strict_types=1);

namespace App\Commands\Node;

use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

final class NodeGrantCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'node:grant
        {consuming_node? : Name of the node requesting access}
        {serving_node? : Name of the node providing access}
        {--preset= : Permission preset to apply}
        {--permissions= : Comma-separated list of permissions}
        {--force : Confirm gateway-admin grant without prompting}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Grant one node access to another.';

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

        $payload = array_filter([
            'consuming_node' => $consumingNode,
            'serving_node' => $servingNode,
            'preset' => $this->stringOption('preset'),
            'permissions' => $this->stringOption('permissions'),
            'force' => (bool) $this->option('force'),
        ], fn (mixed $value): bool => $value !== null);

        try {
            $response = $this->gatewayPost('/api/nodes/grant', $payload);
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

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
