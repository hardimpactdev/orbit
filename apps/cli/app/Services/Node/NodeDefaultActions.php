<?php

declare(strict_types=1);

namespace App\Services\Node;

use App\Exceptions\GatewayApiException;
use App\Services\GatewayApiClient;
use App\Services\OrbitConfigStore;

/**
 * Business logic for the node:default sub-actions.
 *
 * Each method returns a typed result array consumed by NodeDefaultCommand.
 */
final readonly class NodeDefaultActions
{
    public function __construct(
        private OrbitConfigStore $configStore,
        private GatewayApiClient $gatewayClient,
    ) {}

    /**
     * @return array{action: string, default_node: array{name: string, role: string}|null}
     */
    public function show(): array
    {
        $name = $this->configStore->defaultNode();

        return [
            'action' => 'show',
            'default_node' => $name !== null
                ? ['name' => $name, 'role' => 'app-dev']
                : null,
        ];
    }

    /**
     * @return array{action: string, default_node: array{name: string, role: string}, meta: array<string, mixed>}|array{code: string, message: string, meta: array<string, mixed>}
     */
    public function set(string $name): array
    {
        $validation = $this->validateNode($name);

        if ($validation !== null) {
            return $validation;
        }

        $this->configStore->setDefaultNode($name);

        return [
            'action' => 'set',
            'default_node' => ['name' => $name, 'role' => 'app-dev'],
            'meta' => [],
        ];
    }

    /**
     * @return array{action: string, default_node: null, meta: array{was_set: bool}}
     */
    public function clear(): array
    {
        $wasSet = $this->configStore->clearDefaultNode();

        return [
            'action' => 'clear',
            'default_node' => null,
            'meta' => ['was_set' => $wasSet],
        ];
    }

    /**
     * @return list<array{name: string, role: string}>|array{code: string, message: string, meta: array<string, mixed>}
     */
    public function fetchDevelopmentNodes(): array
    {
        try {
            $response = $this->gatewayClient->get('/api/nodes', [
                'role' => 'app-dev',
            ]);
        } catch (GatewayApiException $exception) {
            if ($exception->hasGatewayError() && $exception->gatewayErrorCode() !== null) {
                return [
                    'code' => (string) $exception->gatewayErrorCode(),
                    'message' => (string) ($exception->gatewayErrorMessage() ?? $exception->getMessage()),
                    'meta' => $exception->gatewayErrorMeta(),
                ];
            }

            return [
                'code' => $exception->cliFailureCode(),
                'message' => 'Gateway connection is required to set a default node.',
                'meta' => [],
            ];
        }

        $nodes = $response['success']['data']['nodes'] ?? [];

        if (! is_array($nodes)) {
            return [];
        }

        $result = [];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $nodeName = $node['name'] ?? null;
            $nodeRole = $node['role'] ?? null;

            if (! is_string($nodeName) || $nodeName === '') {
                continue;
            }

            if ($nodeRole !== 'app-dev') {
                continue;
            }

            $result[] = ['name' => $nodeName, 'role' => 'app-dev'];
        }

        return $result;
    }

    /**
     * @return array{code: string, message: string, meta: array<string, mixed>}|null
     */
    private function validateNode(string $name): ?array
    {
        $nodesOrError = $this->fetchDevelopmentNodes();

        if (isset($nodesOrError['code'])) {
            /** @var array{code: string, message: string, meta: array<string, mixed>} $nodesOrError */
            return $nodesOrError;
        }

        /** @var list<array{name: string, role: string}> $nodesOrError */
        $found = array_filter($nodesOrError, fn (array $n): bool => $n['name'] === $name);

        if ($found !== []) {
            return null;
        }

        return [
            'code' => 'node.not_found',
            'message' => "Node '{$name}' not found or not visible.",
            'meta' => ['name' => $name],
        ];
    }
}
