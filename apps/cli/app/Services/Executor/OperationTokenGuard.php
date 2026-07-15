<?php

declare(strict_types=1);

namespace App\Services\Executor;

use App\Exceptions\OperationTokenGuardException;
use App\Services\GatewayApiClient;
use Closure;
use InvalidArgumentException;
use Orbit\Core\Security\OperationToken;
use SensitiveParameter;
use Throwable;

final readonly class OperationTokenGuard
{
    public function __construct(
        /** @var Closure(): GatewayApiClient */
        private Closure $resolveGateway,
    ) {}

    public function verify(
        #[SensitiveParameter]
        string $compactToken,
        string $expectedCommand,
    ): void {
        if ($expectedCommand === '') {
            throw new OperationTokenGuardException;
        }

        if ($this->agentPushAlreadyAuthorized($compactToken, $expectedCommand)) {
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

    /**
     * @return array{
     *     operation_token: string,
     *     command: string,
     *     argv: list<string>,
     *     cwd?: string,
     *     environment: array<string, string>,
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
        $environment = [];

        foreach ([
            'APP_KEY',
            'HOME',
            'ORBIT_CONFIG_PATH',
            'ORBIT_INSTALL_METADATA_PATH',
            'ORBIT_WG_EASY_DB_PATH',
        ] as $key) {
            $value = getenv($key);

            if (! is_string($value) || $value === '') {
                continue;
            }

            $environment[$key] = $value;
        }

        return $environment;
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
}
