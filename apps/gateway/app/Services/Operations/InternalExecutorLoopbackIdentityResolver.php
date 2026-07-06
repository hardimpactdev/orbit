<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use Illuminate\Http\Request;
use Orbit\Core\Security\OperationToken;

final readonly class InternalExecutorLoopbackIdentityResolver
{
    public function __construct(
        private OperationTokenIntrospector $introspector,
    ) {}

    public function resolve(Request $request, string $peerAddress): ?Node
    {
        if (! $request->is('api/internal-executor/token/verify') || ! $this->isLoopbackAddress($peerAddress)) {
            return null;
        }

        $compactToken = $this->stringInput($request, 'operation_token');
        $expectedCommand = $this->stringInput($request, 'command');

        if ($compactToken === null || $expectedCommand === null) {
            return null;
        }

        try {
            $token = OperationToken::parse($compactToken);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $node = Node::query()
            ->where('name', $token->node)
            ->where('status', NodeStatus::Active->value)
            ->first();

        if (! $node instanceof Node || ! $node->hasActiveRole(NodeRoleName::Gateway->value)) {
            return null;
        }

        $introspection = $this->introspector->introspect(
            compactToken: $compactToken,
            expectedNode: $node->name,
            expectedCommand: $expectedCommand,
        );

        return $introspection['allowed'] ? $node : null;
    }

    private function isLoopbackAddress(string $peerAddress): bool
    {
        return in_array($peerAddress, ['127.0.0.1', '::1'], strict: true);
    }

    private function stringInput(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) ? $value : null;
    }
}
