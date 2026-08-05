<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

use App\Models\Node;
use Orbit\Sdk\Laravel\GatewayApiException;

final readonly class ApplicationLogNodeConstraint
{
    public static function assert(Node $serving, ?string $nodeConstraint): void
    {
        if ($nodeConstraint === null || $nodeConstraint === '') {
            return;
        }

        if ($serving->name !== $nodeConstraint) {
            throw new GatewayApiException(
                "The --node value must match the serving node '{$serving->name}'.",
                'validation_failed',
                [
                    'field' => 'node',
                    'node' => $nodeConstraint,
                    'serving_node' => $serving->name,
                    'reason' => 'node_mismatch',
                ],
            );
        }
    }
}
