<?php

declare(strict_types=1);

namespace App\Services\Operations;

use Closure;
use Orbit\Core\Security\OperationToken;
use Orbit\Core\Security\OperationTokenCommandContext;
use Orbit\Core\Security\OperationTokenVerifier;
use SensitiveParameter;

final readonly class OperationTokenIntrospector
{
    private Closure $clock;

    /**
     * @param  (Closure(): int)|null  $clock
     */
    public function __construct(
        private OperationTokenVerifier $verifier,
        private array $secretsByKeyId,
        private int $notBeforeSkewSeconds = 5,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    /**
     * @param  list<string>  $argv
     * @param  array<string, string>  $environment
     * @return array{allowed: bool, reason: ?string, operation_id: ?string}
     *
     * @mago-expect lint:excessive-parameter-list
     */
    public function introspect(
        string $compactToken,
        string $expectedNode,
        string $expectedCommand,
        array $argv,
        ?string $cwd,
        array $environment,
        ?string $input,
    ): array {
        try {
            $token = OperationToken::parse($compactToken);
        } catch (\InvalidArgumentException) {
            return $this->denied('invalid_token');
        }

        try {
            $commandContext = OperationTokenCommandContext::fromAgentVerification(
                argv: $argv,
                cwd: $cwd,
                environment: $environment,
                input: $input,
                operationToken: $compactToken,
            );
        } catch (\InvalidArgumentException) {
            return $this->denied('arguments_mismatch', $token->id);
        }

        return $this->introspectParsedToken(
            token: $token,
            expectedNode: $expectedNode,
            expectedCommand: $expectedCommand,
            commandContext: $commandContext,
        );
    }

    /**
     * @return array{allowed: bool, reason: ?string, operation_id: ?string}
     */
    public function introspectTrustedContext(
        #[SensitiveParameter]
        string $compactToken,
        string $expectedNode,
        string $expectedCommand,
        OperationTokenCommandContext $commandContext,
    ): array {
        try {
            $token = OperationToken::parse($compactToken);
        } catch (\InvalidArgumentException) {
            return $this->denied('invalid_token');
        }

        return $this->introspectParsedToken(
            token: $token,
            expectedNode: $expectedNode,
            expectedCommand: $expectedCommand,
            commandContext: $commandContext,
        );
    }

    /**
     * @return array{allowed: bool, reason: ?string, operation_id: ?string}
     */
    private function introspectParsedToken(
        #[SensitiveParameter]
        OperationToken $token,
        string $expectedNode,
        string $expectedCommand,
        OperationTokenCommandContext $commandContext,
    ): array {
        if (! hash_equals($expectedNode, $token->node)) {
            return $this->denied('target_node_mismatch', $token->id);
        }

        if (! hash_equals($expectedCommand, $token->command)) {
            return $this->denied('command_mismatch', $token->id);
        }

        if (! hash_equals($token->commandContextHash, $commandContext->hash())) {
            return $this->denied('arguments_mismatch', $token->id);
        }

        if (! $this->verifier->verify(
            secretsByKeyId: $this->secretsByKeyId,
            token: $token,
            expectedNode: $expectedNode,
            expectedCommand: $expectedCommand,
            expectedCommandContextHash: $commandContext->hash(),
            now: $this->now(),
            notBeforeSkewSeconds: $this->notBeforeSkewSeconds,
        )) {
            return $this->denied('invalid_token', $token->id);
        }

        return [
            'allowed' => true,
            'reason' => null,
            'operation_id' => $token->id,
        ];
    }

    /**
     * @return array{allowed: false, reason: string, operation_id: ?string}
     */
    private function denied(string $reason, ?string $operationId = null): array
    {
        return [
            'allowed' => false,
            'reason' => $reason,
            'operation_id' => $operationId,
        ];
    }

    private function now(): int
    {
        $now = ($this->clock)();

        if (! is_int($now)) {
            return time();
        }

        return $now;
    }
}
