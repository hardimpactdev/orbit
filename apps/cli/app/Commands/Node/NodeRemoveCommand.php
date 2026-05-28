<?php

declare(strict_types=1);

namespace App\Commands\Node;

use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

final class NodeRemoveCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'node:remove
        {name? : Name of the node to remove}
        {--force : Confirm destructive operation without prompting}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Remove a node from the registry.';

    public function handle(): int
    {
        $name = $this->stringArgument('name');

        if ($name === null) {
            return $this->renderFailure('validation_failed', 'Node name is required.', ['field' => 'name']);
        }

        if (! (bool) $this->option('force')) {
            return $this->renderFailure('validation_failed', 'Use --force to remove this node.', ['field' => 'force']);
        }

        try {
            $response = $this->gatewayDelete('/api/nodes/'.rawurlencode($name), [
                'destructive_consent' => true,
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
