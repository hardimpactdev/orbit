<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;

/** Never admit a deploy that cannot compose its required owning-family children. */
final class InstanceDeploymentAvailability
{
    /** @param array<string,mixed> $intent */
    public static function assertAvailable(array $intent): void
    {
        if (! OperationKinds::supports('children')) {
            throw self::unavailable();
        }
        $required = [];
        if (($intent['variant'] ?? null) !== 'development') {
            $required[] = 'process.restart_instance';
            if (($intent['php'] ?? false) === true) {
                $required[] = 'node.prepare_php_packages';
            }
        }
        if (($intent['variant'] ?? null) === 'production') {
            $required = [...$required, 'instance.sync_environment', 'process.install_instance_definitions', 'schedule.install_instance_definitions'];
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
