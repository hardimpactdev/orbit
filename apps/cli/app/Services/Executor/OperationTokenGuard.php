<?php

declare(strict_types=1);

namespace App\Services\Executor;

use App\Exceptions\OperationTokenGuardException;
use App\Services\GatewayApiClient;
use Closure;
use Throwable;

final readonly class OperationTokenGuard
{
    public function __construct(
        /** @var Closure(): GatewayApiClient */
        private Closure $resolveGateway,
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
}
