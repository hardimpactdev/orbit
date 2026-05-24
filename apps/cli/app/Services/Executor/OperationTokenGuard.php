<?php

declare(strict_types=1);

namespace App\Services\Executor;

use App\Exceptions\OperationTokenGuardException;
use Orbit\Core\Security\OperationToken;
use Orbit\Core\Security\OperationTokenVerifier;
use Throwable;

final class OperationTokenGuard
{
    public function __construct(
        private ?string $secret,
        private ?string $expectedNode,
        private OperationTokenVerifier $verifier,
    ) {}

    public function verify(string $compactToken, string $expectedCommand): OperationToken
    {
        if ($this->secret === null || $this->expectedNode === null || $expectedCommand === '') {
            throw new OperationTokenGuardException;
        }

        try {
            $token = OperationToken::parse($compactToken);
        } catch (Throwable) {
            throw new OperationTokenGuardException;
        }

        $isValid = $this->verifier->verify(
            secret: $this->secret,
            token: $token,
            expectedNode: $this->expectedNode,
            expectedCommand: $expectedCommand,
        );

        if (! $isValid) {
            throw new OperationTokenGuardException;
        }

        return $token;
    }
}
