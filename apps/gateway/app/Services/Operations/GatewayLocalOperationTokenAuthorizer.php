<?php

declare(strict_types=1);

namespace App\Services\Operations;

use Orbit\Core\Security\OperationTokenCommandContext;
use Orbit\Core\Security\TrustedExecutionContext;
use RuntimeException;
use SensitiveParameter;

final readonly class GatewayLocalOperationTokenAuthorizer
{
    public function __construct(
        private OperationTokenIntrospector $introspector,
        private OperationTokenConsumptionGuard $consumptionGuard,
    ) {}

    public function authorize(
        #[SensitiveParameter]
        string $compactToken,
        string $expectedNode,
        string $expectedCommand,
        OperationTokenCommandContext $commandContext,
    ): TrustedExecutionContext {
        $result = $this->introspector->introspectTrustedContext(
            compactToken: $compactToken,
            expectedNode: $expectedNode,
            expectedCommand: $expectedCommand,
            commandContext: $commandContext,
        );
        $operationId = $result['operation_id'];

        if (! $result['allowed'] || $operationId === null) {
            throw new RuntimeException('Gateway-local operation token authorization failed.');
        }

        if ($this->consumptionGuard->consume($operationId) !== OperationTokenConsumptionResult::Consumed) {
            throw new RuntimeException('Gateway-local operation token was already consumed.');
        }

        return TrustedExecutionContext::fromOperationToken(
            compactToken: $compactToken,
            lane: TrustedExecutionContext::GATEWAY_LOCAL_LANE,
        );
    }
}
