<?php

declare(strict_types=1);

namespace App\Services\Processes;

use Orbit\Sdk\Laravel\GatewayApiException;

/**
 * Shared validation for process target selector modes.
 *
 * Modes: app | node | instance/workspace pairing.
 * `app` is mutually exclusive with node, instance, and workspace.
 */
final class ProcessTargetSelectors
{
    /**
     * @return array{app: ?string, node: ?string, instance: ?string, workspace: ?string}
     */
    public static function normalize(
        ?string $appHostname,
        ?string $nodeName,
        ?string $instanceName,
        ?string $workspaceName,
    ): array {
        return [
            'app' => self::filled($appHostname),
            'node' => self::filled($nodeName),
            'instance' => self::filled($instanceName),
            'workspace' => self::filled($workspaceName),
        ];
    }

    /**
     * @param  array{app: ?string, node: ?string, instance: ?string, workspace: ?string}  $selectors
     */
    public static function assertCompatible(array $selectors): void
    {
        $app = $selectors['app'];
        $node = $selectors['node'];
        $instance = $selectors['instance'];
        $workspace = $selectors['workspace'];

        if ($app !== null && ($node !== null || $instance !== null || $workspace !== null)) {
            throw new GatewayApiException(
                'An app context cannot be combined with node, instance, or workspace context.',
                'validation_failed',
                [
                    'field' => 'context',
                    'app' => $app,
                    'node' => $node,
                    'instance' => $instance,
                    'workspace' => $workspace,
                ],
            );
        }

        if ($node !== null && ($instance !== null || $workspace !== null)) {
            throw new GatewayApiException(
                'A node context cannot be combined with instance or workspace context.',
                'validation_failed',
                [
                    'field' => 'context',
                    'node' => $node,
                    'instance' => $instance,
                    'workspace' => $workspace,
                ],
            );
        }
    }

    private static function filled(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
