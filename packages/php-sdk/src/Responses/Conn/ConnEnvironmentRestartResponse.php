<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Conn;

use InvalidArgumentException;
use Orbit\Sdk\Responses\Operations\OperationResponse;

/** One Instance's answer to a T3 restart for its machine name: `restarting` with its Operation, or `current`, `busy` or `skipped`. */
final readonly class ConnEnvironmentRestartResponse
{
    public const array Answers = ['restarting', 'current', 'busy', 'skipped'];

    public function __construct(public int $instanceId, public string $instance, public string $answer, public ?string $reason, public ?OperationResponse $operation) {}

    /** @param array<array-key, mixed> $data */
    public static function fromGatewayData(array $data, string $requestId): self
    {
        $instanceId = $data['instance_id'] ?? null;
        $instance = $data['instance'] ?? null;
        $answer = $data['answer'] ?? null;
        $reason = $data['reason'] ?? null;
        $operation = $data['operation'] ?? null;

        if (! is_int($instanceId) || ! is_string($instance) || ! in_array($answer, self::Answers, true) || ($reason !== null && ! is_string($reason))
            || ($operation !== null && ! is_array($operation)) || ($answer === 'restarting') !== is_array($operation)) {
            throw new InvalidArgumentException('Gateway response contains an invalid T3 restart answer.');
        }

        /** @var array<string, mixed>|null $operation */
        return new self($instanceId, $instance, $answer, $reason, $operation === null ? null : OperationResponse::fromGatewayData($operation, $requestId));
    }
}
