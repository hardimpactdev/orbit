<?php

declare(strict_types=1);

namespace App\Commands\Node;

use App\Commands\Concerns\ResolvesDefaultNode;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use App\Exceptions\OrbitConfigStoreException;

final class NodeShowCommand extends GatewayCommand
{
    use ResolvesDefaultNode;

    protected $signature = 'node:show {name? : Node name to inspect} {--json}';

    protected $description = 'Show node details from the gateway registry.';

    public function handle(): int
    {
        try {
            $node = $this->nodeArgumentOrDefault('name');
        } catch (OrbitConfigStoreException $exception) {
            return $this->renderFailure($exception->orbitCode, $exception->getMessage());
        }

        if ($node === null) {
            return $this->renderFailure('validation_failed', 'The name argument is required.', ['field' => 'name']);
        }

        $name = rawurlencode($node);

        try {
            $response = $this->gatewayGet("/api/nodes/{$name}");
        } catch (GatewayApiException $exception) {
            return $this->renderFailure($exception->cliFailureCode(), $exception->getMessage());
        }

        return $this->renderSuccess($response);
    }
}
