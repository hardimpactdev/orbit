<?php

declare(strict_types=1);

namespace App\Services\Executor;

use App\Exceptions\OperationTokenGuardException;
use App\Services\GatewayApiClient;
use App\Services\OrbitConfigStore;
use Closure;
use InvalidArgumentException;
use Orbit\Core\Security\OperationToken;
use Orbit\Core\Security\OperationTokenSigner;
use Orbit\Core\Security\OperationTokenVerifier;
use Throwable;

final readonly class OperationTokenGuard
{
    public function __construct(
        /** @var Closure(): GatewayApiClient */
        private Closure $resolveGateway,
        /** @var (Closure(): ?string)|null */
        private ?Closure $resolveLocalNode = null,
        private ?OperationTokenVerifier $localVerifier = null,
    ) {}

    public function verify(string $compactToken, string $expectedCommand): void
    {
        if ($expectedCommand === '') {
            throw new OperationTokenGuardException;
        }

        try {
            $response = ($this->resolveGateway)()->post('/api/internal-executor/token/verify', [
                'operation_token' => $compactToken,
                'command' => $expectedCommand,
            ]);
        } catch (Throwable) {
            if ($this->verifyLocally($compactToken, $expectedCommand)) {
                return;
            }

            throw new OperationTokenGuardException;
        }

        if (! $this->isAllowedResponse($response)) {
            throw new OperationTokenGuardException;
        }
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function isAllowedResponse(array $response): bool
    {
        $success = $response['success'] ?? null;

        if (! is_array($success)) {
            return false;
        }

        $data = $success['data'] ?? null;

        if (! is_array($data)) {
            return false;
        }

        return ($data['allowed'] ?? null) === true;
    }

    private function verifyLocally(string $compactToken, string $expectedCommand): bool
    {
        $secret = $this->localSecret();

        if ($secret === null) {
            return false;
        }

        $expectedNode = $this->localNode();

        if ($expectedNode === null) {
            return false;
        }

        try {
            $token = OperationToken::parse($compactToken);
        } catch (InvalidArgumentException) {
            return false;
        }

        return $this->localVerifier()->verify(
            secret: $secret,
            token: $token,
            expectedNode: $expectedNode,
            expectedCommand: $expectedCommand,
        );
    }

    private function localSecret(): ?string
    {
        $configured = config('app.key');

        if (is_string($configured) && trim($configured) !== '') {
            return $configured;
        }

        foreach ([getenv('APP_KEY'), $_SERVER['APP_KEY'] ?? null, $_ENV['APP_KEY'] ?? null] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    private function localNode(): ?string
    {
        if ($this->resolveLocalNode !== null) {
            return $this->normalizeLocalNode(($this->resolveLocalNode)());
        }

        try {
            return $this->normalizeLocalNode(app(OrbitConfigStore::class)->defaultNode());
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizeLocalNode(?string $node): ?string
    {
        if ($node === null) {
            return null;
        }

        $node = trim($node);

        return $node === '' ? null : $node;
    }

    private function localVerifier(): OperationTokenVerifier
    {
        return $this->localVerifier ?? new OperationTokenVerifier(new OperationTokenSigner);
    }
}
