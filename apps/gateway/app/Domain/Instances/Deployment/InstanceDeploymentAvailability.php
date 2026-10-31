<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use LogicException;

/** Never admit a deploy that cannot compose its required owning-family children. */
final class InstanceDeploymentAvailability
{
    /**
     * Release gate: deleting the PHP executor requires all production callers and child contracts.
     *
     * @param  array<string,mixed>|null  $definitions
     */
    public static function assertReleaseReady(?array $definitions = null): void
    {
        $definitions ??= OperationKinds::definitions();
        $required = ['instance.deploy', 'instance.rollback'];
        foreach (['instance.deploy', 'instance.rollback', 'instance.prepare_vp_environment', 'instance.prepare_php_runtime', 'instance.repair_projection'] as $kind) {
            $definition = $definitions[$kind] ?? [];
            if (is_array($definition)) {
                $children = $definition['children'] ?? [];
                if (! is_array($children)) {
                    throw new LogicException('Invalid deployment children.');
                }
                foreach ($children as $child) {
                    if (! is_array($child) || ! is_string($child['kind'] ?? null)) {
                        throw new LogicException('Invalid deployment child kind.');
                    }
                    $required[] = $child['kind'];
                }
            }
        }
        $missing = array_values(array_filter(array_unique($required), static fn (string $kind): bool => ! is_array($definitions[$kind] ?? null) || ($definitions[$kind]['executor'] ?? null) !== 'swamp'));
        if ($missing !== []) {
            throw new LogicException('Instance deployment cutover requires Swamp kinds: '.implode(', ', $missing).'.');
        }
    }

    /** @param array<string,mixed> $intent */
    public static function assertAvailable(array $intent): void
    {
        if (($intent['variant'] ?? null) === 'development') {
            return;
        }
        if (! OperationKinds::supports('children')) {
            throw self::unavailable();
        }
        $required = [];
        if (($intent['runtime_synchronous'] ?? false) !== true) {
            $required[] = 'process.restart_instance';
        }
        if (($intent['php'] ?? false) === true) {
            $required[] = 'node.prepare_php_packages';
        }
        if (($intent['variant'] ?? null) === 'production') {
            $required[] = 'instance.sync_environment';
            if (($intent['runtime_synchronous'] ?? false) !== true) {
                $required = [...$required, 'process.install_instance_definitions', 'schedule.install_instance_definitions'];
            }
        }
        if (($intent['needs_vp'] ?? false) === true) {
            $required = [...$required, 'instance.prepare_vp_environment', 'tool.manager_converge'];
        }
        if (($intent['projection_route_id'] ?? null) !== null) {
            $required[] = 'route.converge';
        }
        $definitions = OperationKinds::definitions();
        foreach ($required as $kind) {
            if (! isset($definitions[$kind])) {
                throw self::unavailable();
            }
        }
    }

    private static function unavailable(): ResourceOperationException
    {
        return new ResourceOperationException('operation.kind_unavailable', 'A required deployment child contract is unavailable.', 503);
    }
}
