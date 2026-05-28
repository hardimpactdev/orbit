<?php

declare(strict_types=1);

namespace App\Commands\Node;

use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

final class NodeAgentIdeCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'node:agent-ide
        {name? : Node name}
        {agent_ide? : Agent IDE adapter}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Set the default agent IDE for a node.';

    public function handle(): int
    {
        $name = $this->stringArgument('name');
        $agentIde = $this->stringArgument('agent_ide');

        if ($name === null) {
            return $this->renderFailure('validation_failed', 'Node name is required.', ['field' => 'name']);
        }

        if ($agentIde === null) {
            return $this->renderFailure('validation_failed', 'Agent IDE adapter is required.', ['field' => 'agent_ide']);
        }

        try {
            $response = $this->gatewayPost('/api/nodes/'.rawurlencode($name).'/agent-ide', [
                'agent_ide' => $agentIde,
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
