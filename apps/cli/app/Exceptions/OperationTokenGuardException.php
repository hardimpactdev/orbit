<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class OperationTokenGuardException extends RuntimeException
{
    public const string DEFAULT_REASON = 'invalid_token';

    /**
     * Closed set of gateway denial reasons safe to surface as CLI failure codes.
     *
     * @var list<string>
     */
    public const array SAFE_REASONS = [
        'invalid_token',
        'arguments_mismatch',
        'target_node_mismatch',
        'command_mismatch',
        'operation.already_dispatched',
        'operation.not_found',
    ];

    public function __construct(
        private readonly string $reason = self::DEFAULT_REASON,
    ) {
        parent::__construct('Operation token is invalid.');
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public static function fromGatewayReason(mixed $reason): self
    {
        if (! is_string($reason) || $reason === '') {
            return new self(self::DEFAULT_REASON);
        }

        if (! in_array($reason, self::SAFE_REASONS, strict: true)) {
            return new self(self::DEFAULT_REASON);
        }

        return new self($reason);
    }
}
