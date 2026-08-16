<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use InvalidArgumentException;

final class NodeCreationRoleInputException extends InvalidArgumentException
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $meta,
    ) {
        parent::__construct($message);
    }

    public static function unsupportedWorkloadRole(): self
    {
        return new self(
            errorCode: 'validation_failed',
            message: 'Node roles must be one or more of app-dev, app-prod, database, agent, ingress, metrics, websocket, s3, or analytics.',
            meta: ['field' => 'roles'],
        );
    }

    /** @param array{0: string, 1: string} $pair */
    public static function conflictingWorkloadRoles(array $pair): self
    {
        return new self(
            errorCode: 'validation_failed',
            message: "Workload roles {$pair[0]} and {$pair[1]} cannot be combined.",
            meta: [
                'field' => 'roles',
                'conflicts' => $pair,
            ],
        );
    }
}
