<?php

declare(strict_types=1);

namespace App\Commands\Node;

use App\Commands\Concerns\PromptsForGatewayRegistryEntities;
use App\Commands\Concerns\RendersShowDetails;
use App\Commands\Concerns\ResolvesDefaultNode;
use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use App\Exceptions\OrbitConfigStoreException;

final class NodeShowCommand extends GatewayCommand
{
    use PromptsForGatewayRegistryEntities;
    use RendersShowDetails;
    use ResolvesDefaultNode;
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'node:show {name? : Node name to inspect} {--json}';

    #[\Override]
    protected $description = 'Show node details from the gateway registry.';

    public function handle(): int
    {
        $node = $this->resolveNodeName();

        if (is_int($node)) {
            return $node;
        }

        $name = rawurlencode($node);

        try {
            $response = $this->gatewayGet("/api/nodes/{$name}");
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $node = $this->nodeFromGatewayResponse($response);

        if ($node === null) {
            return $this->renderFailure('gateway_unavailable', 'Gateway response missing required node data.');
        }

        $this->renderNode($node);

        return self::SUCCESS;
    }

    private function resolveNodeName(): string|int
    {
        $name = $this->stringArgument('name');

        if ($name !== null) {
            return $name;
        }

        if ($this->canPromptForRegistrySelection()) {
            return $this->promptForVisibleNode();
        }

        try {
            $node = $this->nodeArgumentOrDefault('name');
        } catch (OrbitConfigStoreException $exception) {
            return $this->renderFailure($exception->orbitCode, $exception->getMessage());
        }

        if ($node === null) {
            return $this->renderFailure('validation_failed', 'The name argument is required.', ['field' => 'name']);
        }

        return $node;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>|null
     */
    private function nodeFromGatewayResponse(array $response): ?array
    {
        $node = $response['success']['data']['node'] ?? null;

        return is_array($node) ? $node : null;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function renderNode(array $node): void
    {
        $roles = $node['roles'] ?? [];
        $addresses = is_array($node['addresses'] ?? null) ? $node['addresses'] : [];
        $grants = is_array($node['grants'] ?? null) ? $node['grants'] : [];

        $properties = [
            ...$this->humanRoleProperties($roles),
            'OS' => is_scalar($node['platform'] ?? null) ? (string) $node['platform'] : 'unknown',
            'Peer IP' => is_scalar($addresses['wireguard'] ?? null) ? (string) $addresses['wireguard'] : '',
            'Serving' => $this->humanGrantLabels($grants['consuming_nodes'] ?? []),
            'Consuming' => $this->humanGrantLabels($grants['serving_nodes'] ?? []),
        ];

        $name = is_scalar($node['name'] ?? null) ? (string) $node['name'] : 'unknown';

        $this->renderShowDetails("Node: {$name}", $properties);
    }

    /**
     * @return array{Role: string}|array{Roles: string}
     */
    private function humanRoleProperties(mixed $roles): array
    {
        if (! is_array($roles) || $roles === []) {
            return ['Role' => '—'];
        }

        $labels = $this->humanRoleLabels($roles);

        if ($labels === []) {
            return ['Role' => '—'];
        }

        if (count($labels) === 1) {
            return ['Role' => $labels[0]];
        }

        return ['Roles' => implode(', ', $labels)];
    }

    /**
     * @return list<string>
     */
    private function humanRoleLabels(array $roles): array
    {
        $labels = [];

        foreach ($roles as $role) {
            if (! is_array($role) || ! is_string($role['role'] ?? null) || $role['role'] === '') {
                continue;
            }

            $status = is_string($role['status'] ?? null) ? $role['status'] : 'active';

            $labels[] = $status === 'active'
                ? $role['role']
                : "{$role['role']} ({$status})";
        }

        return $labels;
    }

    /**
     * @return list<string>
     */
    private function humanGrantLabels(mixed $grants): array
    {
        if (! is_array($grants)) {
            return [];
        }

        $labels = [];

        foreach ($grants as $grant) {
            if (! is_array($grant) || ! is_string($grant['name'] ?? null)) {
                continue;
            }

            $permissions = [];

            if (is_array($grant['permissions'] ?? null)) {
                foreach ($grant['permissions'] as $permission) {
                    if (is_string($permission)) {
                        $permissions[] = $permission;
                    }
                }
            }

            $labels[] = $grant['name'].': '.implode(', ', $permissions !== [] ? $permissions : ['*']);
        }

        return $labels;
    }
}
