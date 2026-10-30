<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Tools\ToolOperationCompletion;
use App\Infrastructure\AgentCommands\AgentContract;
use LogicException;

/** The code-owned names a family build may use. No family or caller extends it. */
final readonly class OperationFamilyCatalogue
{
    private const array Projections = ['tools.record_intent' => ToolOperationCompletion::class];

    public function __construct(private OperationGatewayCommands $gateway) {}

    public function json(): string
    {
        $path = __DIR__.'/../../../../agent/contract/commands.json';
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new LogicException('The pinned agent contract is missing.');
        }
        $agent = new AgentContract($path);
        $names = $agent->operationNames();
        sort($names);
        $gateway = $this->gateway->names();
        sort($gateway);
        foreach ($gateway as $name) {
            $this->gateway->get($name);
        }

        return json_encode([
            'schema' => 'orbit.family-catalogue/v1',
            'agent_contract_sha256' => hash('sha256', $bytes),
            'commands' => [
                'orbit-agent' => array_map(static fn (string $name): array => ['name' => $name, 'mutates' => $agent->command($name)->mutates], $names),
                // Gateway-local effects have no read-only classification; require keyed execution.
                'gateway' => array_map(static fn (string $name): array => ['name' => $name, 'mutates' => true], $gateway),
            ],
            'projections' => array_keys(self::Projections),
            'invariants' => [],
            // Register a binding only with its implemented typed method and tests.
            // Generic SSH, arbitrary behavior methods and shell are never bindings.
            'native_methods' => [],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
    }

    public function digest(): string
    {
        return hash('sha256', $this->json());
    }

    public function projection(string $name): OperationCompletion
    {
        $class = self::Projections[$name] ?? throw new LogicException('Unknown family projection.');

        return app($class);
    }
}
