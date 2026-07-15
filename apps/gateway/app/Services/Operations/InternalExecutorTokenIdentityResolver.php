<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Enums\Nodes\NodeRoleName;
use App\Models\Node;
use Illuminate\Http\Request;
use Orbit\Core\Security\OperationToken;

/**
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class InternalExecutorTokenIdentityResolver
{
    public function __construct(
        private OperationTokenIntrospector $introspector,
    ) {}

    public function resolve(Request $request, string $peerAddress): ?Node
    {
        if (
            ! $request->is('api/internal-executor/token/verify')
            || filter_var($peerAddress, FILTER_VALIDATE_IP) === false
        ) {
            return null;
        }

        $compactToken = $this->stringInput($request, 'operation_token');
        $expectedCommand = $this->stringInput($request, 'command');
        $argv = $this->stringListInput($request, 'argv');
        $environment = $this->stringMapInput($request, 'environment');
        $cwd = $this->stringInput($request, 'cwd');
        $input = $this->stringInput($request, 'input');

        if ($compactToken === null || $expectedCommand === null || $argv === null || $environment === null) {
            return null;
        }

        try {
            $token = OperationToken::parse($compactToken);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $node = Node::query()
            ->where('name', $token->node)
            ->first();

        if (! $node instanceof Node) {
            return null;
        }

        $isActiveGateway = $node->isActive() && $node->hasActiveRole(NodeRoleName::Gateway->value);
        $isProvisioningPeer = $node->isProvisioning() && trim((string) $node->wireguard_address) === $peerAddress;

        if (! $isActiveGateway && ! $isProvisioningPeer) {
            return null;
        }

        $introspection = $this->introspector->introspect(
            compactToken: $compactToken,
            expectedNode: $node->name,
            expectedCommand: $expectedCommand,
            argv: $argv,
            cwd: $cwd,
            environment: $environment,
            input: $input,
        );

        return $introspection['allowed'] ? $node : null;
    }

    private function stringInput(Request $request, string $key): ?string
    {
        if (! is_string($request->input($key))) {
            return null;
        }

        return $request->string($key)->toString();
    }

    /**
     * @return list<string>|null
     */
    private function stringListInput(Request $request, string $key): ?array
    {
        if (! is_array($request->input($key))) {
            return null;
        }

        $value = $request->array($key);

        if (! array_is_list($value)) {
            return null;
        }

        foreach ($value as $item) {
            if (! is_string($item)) {
                return null;
            }
        }

        /** @var list<string> $value */
        return $value;
    }

    /**
     * @return array<string, string>|null
     */
    private function stringMapInput(Request $request, string $key): ?array
    {
        if (! $request->has($key)) {
            return [];
        }

        if (! is_array($request->input($key))) {
            return null;
        }

        $value = $request->array($key);

        foreach ($value as $itemKey => $itemValue) {
            if (! is_string($itemKey) || ! is_string($itemValue)) {
                return null;
            }
        }

        /** @var array<string, string> $value */
        return $value;
    }
}
