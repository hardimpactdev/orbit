<?php

declare(strict_types=1);

namespace App\Services\Executor;

use App\Exceptions\OperationTokenGuardException;
use App\Services\GatewayApiClient;
use Closure;
use InvalidArgumentException;
use Orbit\Core\Security\OperationToken;
use Orbit\Core\Security\OperationTokenEnvironment;
use Orbit\Core\Security\TrustedExecutionContext;
use SensitiveParameter;
use Throwable;

final readonly class OperationTokenGuard
{
    public function __construct(
        /** @var Closure(): GatewayApiClient */
        private Closure $resolveGateway,
        private OperationStdinBuffer $stdinBuffer = new OperationStdinBuffer,
    ) {}

    public function verify(
        #[SensitiveParameter]
        string $compactToken,
        string $expectedCommand,
    ): void {
        if ($expectedCommand === '') {
            throw new OperationTokenGuardException;
        }

        // Capture piped stdin before verify so bound input can be hashed and later
        // re-read by the internal command without an empty stream.
        $this->stdinBuffer->captureFromProcessStdin();

        if (
            $this->trustedExecutionAlreadyAuthorized($compactToken, $expectedCommand)
            || $this->agentPushAlreadyAuthorized($compactToken, $expectedCommand)
        ) {
            return;
        }

        try {
            $response = ($this->resolveGateway)()->post(
                '/api/internal-executor/token/verify',
                $this->verificationPayload($compactToken, $expectedCommand),
            );
        } catch (Throwable) {
            throw new OperationTokenGuardException;
        }

        if ($this->isAllowedResponse($response)) {
            return;
        }

        throw OperationTokenGuardException::fromGatewayReason(
            $this->safeDenialReason($response),
        );
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function isAllowedResponse(array $response): bool
    {
        $data = $this->verificationData($response);

        if ($data === null) {
            return false;
        }

        return ($data['allowed'] ?? null) === true;
    }

    /**
     * Extract a candidate denial reason only from a well-formed allowed=false body.
     * Malformed responses never expose free-form gateway text.
     *
     * @param  array<string, mixed>  $response
     */
    private function safeDenialReason(array $response): mixed
    {
        $data = $this->verificationData($response);

        if ($data === null || ($data['allowed'] ?? null) !== false) {
            return null;
        }

        return $data['reason'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<array-key, mixed>|null
     */
    private function verificationData(array $response): ?array
    {
        $success = $response['success'] ?? null;

        if (! is_array($success)) {
            return null;
        }

        $data = $success['data'] ?? null;

        if (! is_array($data)) {
            return null;
        }

        return $data;
    }

    /**
     * @return array{
     *     operation_token: string,
     *     command: string,
     *     argv: list<string>,
     *     cwd?: string,
     *     environment: array<string, string>,
     *     input?: string,
     *     consume: true,
     * }
     */
    private function verificationPayload(
        #[SensitiveParameter]
        string $compactToken,
        string $expectedCommand,
    ): array {
        $payload = [
            'operation_token' => $compactToken,
            'command' => $expectedCommand,
            'argv' => $this->currentArgv($expectedCommand, $compactToken),
            'environment' => $this->verificationEnvironment(),
            'consume' => true,
        ];
        $cwd = getcwd();

        if (is_string($cwd) && $cwd !== '') {
            $payload['cwd'] = $cwd;
        }

        $input = $this->stdinBuffer->contents();

        if (is_string($input) && $input !== '') {
            $payload['input'] = $input;
        }

        return $payload;
    }

    /**
     * @return list<string>
     */
    private function currentArgv(
        string $expectedCommand,
        #[SensitiveParameter]
        string $compactToken,
    ): array {
        $argv = $_SERVER['argv'] ?? [];

        if (is_array($argv)) {
            foreach ($argv as $index => $argument) {
                if ($argument === $expectedCommand) {
                    /** @var list<string> $commandArgv */
                    return array_values(array_slice($argv, $index));
                }
            }
        }

        return [
            $expectedCommand,
            "--operation-token={$compactToken}",
        ];
    }

    /**
     * @return array<string, string>
     */
    private function verificationEnvironment(): array
    {
        return OperationTokenEnvironment::fromProcess();
    }

    private function agentPushAlreadyAuthorized(
        #[SensitiveParameter]
        string $compactToken,
        string $expectedCommand,
    ): bool {
        $authorizedOperationId = getenv('ORBIT_AGENT_PUSH_AUTHORIZED_OPERATION_ID');
        $authorizedCommand = getenv('ORBIT_AGENT_PUSH_AUTHORIZED_COMMAND');
        $authorizedToken = getenv('ORBIT_AGENT_PUSH_AUTHORIZED_OPERATION_TOKEN');

        if (
            ! is_string($authorizedOperationId)
            || ! is_string($authorizedCommand)
            || ! is_string($authorizedToken)
            || ! hash_equals($compactToken, $authorizedToken)
            || ! hash_equals($expectedCommand, $authorizedCommand)
        ) {
            return false;
        }

        try {
            $token = OperationToken::parse($compactToken);
        } catch (InvalidArgumentException) {
            return false;
        }

        return hash_equals($token->id, $authorizedOperationId) && hash_equals($token->command, $expectedCommand);
    }

    private function trustedExecutionAlreadyAuthorized(
        #[SensitiveParameter]
        string $compactToken,
        string $expectedCommand,
    ): bool {
        $environment = [];

        foreach ([
            TrustedExecutionContext::LANE_ENVIRONMENT_KEY,
            TrustedExecutionContext::OPERATION_ID_ENVIRONMENT_KEY,
            TrustedExecutionContext::COMMAND_ENVIRONMENT_KEY,
            TrustedExecutionContext::OPERATION_TOKEN_ENVIRONMENT_KEY,
        ] as $key) {
            $value = getenv($key);

            if (! is_string($value)) {
                return false;
            }

            $environment[$key] = $value;
        }

        return (
            TrustedExecutionContext::fromEnvironment($environment)?->authorizes(
                compactToken: $compactToken,
                expectedCommand: $expectedCommand,
            ) === true
        );
    }
}
