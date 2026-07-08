<?php

declare(strict_types=1);

namespace App\Services\Operations;

use Closure;
use InvalidArgumentException;
use Orbit\Core\Security\OperationToken;
use Orbit\Core\Security\OperationTokenCommandContext;
use Orbit\Core\Security\OperationTokenSigner;

final readonly class OperationTokenFactory
{
    private Closure $clock;

    /**
     * @param  (Closure(): int)|null  $clock
     */
    public function __construct(
        private OperationTokenSigner $signer,
        private string $secret,
        private int $ttlSeconds,
        private string $keyId = 'current',
        ?Closure $clock = null,
    ) {
        if (trim($secret) === '') {
            throw new InvalidArgumentException('Operation token signing secret is required.');
        }

        if (trim($keyId) === '') {
            throw new InvalidArgumentException('Operation token signing key id is required.');
        }

        if ($ttlSeconds < 1) {
            throw new InvalidArgumentException('Operation token TTL must be at least one second.');
        }

        $this->clock = $clock ?? time(...);
    }

    public function mint(
        string $operationId,
        string $targetNode,
        string $command,
        ?OperationTokenCommandContext $commandContext = null,
    ): OperationToken {
        $this->ensurePayloadFieldPresent($operationId);
        $this->ensurePayloadFieldPresent($targetNode);
        $this->ensurePayloadFieldPresent($command);

        $issuedAt = ($this->clock)();

        if (! is_int($issuedAt) || $issuedAt < 0) {
            throw new InvalidArgumentException('Operation token clock returned an invalid timestamp.');
        }

        if ($issuedAt > (PHP_INT_MAX - $this->ttlSeconds)) {
            throw new InvalidArgumentException('Operation token expiry timestamp is invalid.');
        }

        return $this->signer->sign(
            secret: $this->secret,
            keyId: $this->keyId,
            id: $operationId,
            node: $targetNode,
            command: $command,
            commandContextHash: ($commandContext ?? $this->defaultCommandContext($command))->hash(),
            issuedAt: $issuedAt,
            expiresAt: $issuedAt + $this->ttlSeconds,
        );
    }

    private function ensurePayloadFieldPresent(string $value): void
    {
        if (trim($value) !== '') {
            return;
        }

        throw new InvalidArgumentException('Operation token payload is incomplete.');
    }

    private function defaultCommandContext(string $command): OperationTokenCommandContext
    {
        return OperationTokenCommandContext::fromTrustedDispatch(
            argv: [
                $command,
                '--operation-token='.OperationTokenCommandContext::OPERATION_TOKEN_SENTINEL,
            ],
            cwd: null,
            environment: [],
            input: null,
        );
    }
}
