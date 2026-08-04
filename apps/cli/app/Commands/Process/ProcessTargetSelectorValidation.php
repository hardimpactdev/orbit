<?php

declare(strict_types=1);

namespace App\Commands\Process;

/**
 * Pure mutual-exclusion checks for process target selector modes.
 *
 * @phpstan-type Failure array{field: string, message: string, meta: array<string, mixed>}
 */
final class ProcessTargetSelectorValidation
{
    /**
     * @return Failure|null
     */
    public static function conflict(
        ?string $appHostname,
        ?string $node,
        ?string $instance,
        ?string $workspace,
    ): ?array {
        if ($appHostname !== null && ($node !== null || $instance !== null || $workspace !== null)) {
            return [
                'field' => 'context',
                'message' => 'An app context cannot be combined with node, instance, or workspace context.',
                'meta' => [
                    'app' => $appHostname,
                    'node' => $node,
                    'instance' => $instance,
                    'workspace' => $workspace,
                ],
            ];
        }

        if ($node !== null && ($instance !== null || $workspace !== null)) {
            return [
                'field' => 'context',
                'message' => 'A node context cannot be combined with instance or workspace context.',
                'meta' => [
                    'node' => $node,
                    'instance' => $instance,
                    'workspace' => $workspace,
                ],
            ];
        }

        return null;
    }

    /**
     * @return Failure|null
     */
    public static function missing(
        ?string $appHostname,
        ?string $node,
        ?string $instance,
        ?string $workspace,
    ): ?array {
        if ($appHostname !== null || $node !== null || $instance !== null || $workspace !== null) {
            return null;
        }

        return [
            'field' => 'instance',
            'message' => 'A node, instance, workspace, or app context is required.',
            'meta' => [],
        ];
    }
}
