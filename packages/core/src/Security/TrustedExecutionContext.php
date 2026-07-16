<?php

declare(strict_types=1);

namespace Orbit\Core\Security;

use InvalidArgumentException;
use SensitiveParameter;

final readonly class TrustedExecutionContext
{
    public const string AGENT_PUSH_LANE = 'agent-push';

    public const string GATEWAY_LOCAL_LANE = 'gateway-local';

    public const string LANE_ENVIRONMENT_KEY = 'ORBIT_TRUSTED_EXECUTION_LANE';

    public const string OPERATION_ID_ENVIRONMENT_KEY = 'ORBIT_TRUSTED_EXECUTION_OPERATION_ID';

    public const string COMMAND_ENVIRONMENT_KEY = 'ORBIT_TRUSTED_EXECUTION_COMMAND';

    public const string OPERATION_TOKEN_ENVIRONMENT_KEY = 'ORBIT_TRUSTED_EXECUTION_OPERATION_TOKEN';

    private function __construct(
        public string $lane,
        public string $operationId,
        public string $command,
        #[SensitiveParameter]
        private string $operationToken,
    ) {}

    public static function fromOperationToken(
        #[SensitiveParameter]
        string $compactToken,
        string $lane,
    ): self {
        self::ensureLane($lane);
        $token = OperationToken::parse($compactToken);

        return new self(
            lane: $lane,
            operationId: $token->id,
            command: $token->command,
            operationToken: $compactToken,
        );
    }

    /**
     * @param  array<string, string>  $environment
     */
    public static function fromEnvironment(array $environment): ?self
    {
        $lane = $environment[self::LANE_ENVIRONMENT_KEY] ?? null;
        $operationId = $environment[self::OPERATION_ID_ENVIRONMENT_KEY] ?? null;
        $command = $environment[self::COMMAND_ENVIRONMENT_KEY] ?? null;
        $operationToken = $environment[self::OPERATION_TOKEN_ENVIRONMENT_KEY] ?? null;

        if (
            ! is_string($lane)
            || ! is_string($operationId)
            || ! is_string($command)
            || ! is_string($operationToken)
        ) {
            return null;
        }

        try {
            $context = self::fromOperationToken($operationToken, $lane);
        } catch (InvalidArgumentException) {
            return null;
        }

        if (
            ! hash_equals($context->operationId, $operationId)
            || ! hash_equals($context->command, $command)
        ) {
            return null;
        }

        return $context;
    }

    /**
     * @return array<string, string>
     */
    public function environment(): array
    {
        return [
            self::LANE_ENVIRONMENT_KEY => $this->lane,
            self::OPERATION_ID_ENVIRONMENT_KEY => $this->operationId,
            self::COMMAND_ENVIRONMENT_KEY => $this->command,
            self::OPERATION_TOKEN_ENVIRONMENT_KEY => $this->operationToken,
        ];
    }

    public function authorizes(
        #[SensitiveParameter]
        string $compactToken,
        string $expectedCommand,
    ): bool {
        return hash_equals($this->operationToken, $compactToken) && hash_equals($this->command, $expectedCommand);
    }

    private static function ensureLane(string $lane): void
    {
        if (in_array($lane, [self::AGENT_PUSH_LANE, self::GATEWAY_LOCAL_LANE], strict: true)) {
            return;
        }

        throw new InvalidArgumentException('Trusted execution lane is invalid.');
    }
}
