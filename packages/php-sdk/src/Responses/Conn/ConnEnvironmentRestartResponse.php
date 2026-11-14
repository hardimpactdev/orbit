<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Conn;

use InvalidArgumentException;

/**
 * One Instance's answer to a T3 restart for its machine name: `restarting` with the Operation to follow, `current`, `busy`, `skipped`,
 * or `failed` (with the failed Operation when its dispatch failed).
 */
final readonly class ConnEnvironmentRestartResponse
{
    public const array Answers = ['restarting', 'current', 'busy', 'skipped', 'failed'];

    public function __construct(public int $instanceId, public string $instance, public string $answer, public ?string $reason, public ?int $operationId) {}

    /** @param array<array-key, mixed> $data */
    public static function fromGatewayData(array $data): self
    {
        $instanceId = $data['instance_id'] ?? null;
        $instance = $data['instance'] ?? null;
        $answer = $data['answer'] ?? null;
        $reason = $data['reason'] ?? null;
        $operationId = $data['operation_id'] ?? null;

        if (! is_int($instanceId) || ! is_string($instance) || ! is_string($answer) || ! in_array($answer, self::Answers, true) || ($reason !== null && ! is_string($reason))
            || ($operationId !== null && (! is_int($operationId) || $operationId < 1)) || ($answer === 'restarting' && $operationId === null)) {
            throw new InvalidArgumentException('Gateway response contains an invalid T3 restart answer.');
        }

        return new self($instanceId, $instance, $answer, $reason, $operationId);
    }
}
