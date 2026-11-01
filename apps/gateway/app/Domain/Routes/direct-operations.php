<?php

declare(strict_types=1);
use App\Domain\Routes\DirectRoutePublication;
use App\Domain\Routes\NativeCaddyPublicationProjection;
use App\Domain\Routes\NativeRouteAdmission;
use App\Domain\Routes\NativeRouteConvergenceProjection;
use App\Domain\Routes\NativeRouteLanRemovalProjection;
use App\Domain\Routes\NativeRouteRemovalProjection;
use App\Domain\Routes\NativeRouteTargetProjection;
use App\Domain\Routes\NativeRouteUpdateProjection;
use App\Domain\Routes\RouteOperationProjection;

$publication = [
    'workflow' => 'operation-route-publication',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'route_publication_writer',
    'projection' => RouteOperationProjection::class,
];

return [
    'route.update' => [
        'workflow' => 'operation-route-update', 'projection' => NativeRouteUpdateProjection::class,
        'workflow_inputs' => true, 'terminal_result' => ['job' => 'update', 'step' => 'result'],
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^route-[a-f0-9]{16}-(forward|restore)-route_material_[0-9]+$']],
    ],
    'route.create' => [
        'workflow' => 'operation-route-create', 'projection' => NativeRouteConvergenceProjection::class,
        'private_material' => NativeRouteAdmission::privateMaterial(),
    ],
    'route.target_set' => [
        'workflow' => 'operation-route-target-set', 'projection' => NativeRouteTargetProjection::class,
        'private_material' => NativeRouteAdmission::privateMaterial(),
    ],
    ...array_fill_keys(['route.destroy', 'route.withdraw'], [
        'workflow' => 'operation-route-removal', 'projection' => NativeRouteRemovalProjection::class,
        'workflow_inputs' => true,
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^route-removal-[a-f0-9]{16}-route_environment_[0-9]+$']],
    ]),
    'route.target_unset' => [
        'workflow' => 'operation-route-target-unset', 'writer_config' => 'swamp.direct_writers',
        'writer_state' => 'route_target_unset_writer', 'projection' => RouteOperationProjection::class,
    ],
    'route.lan_remove' => [
        'workflow' => 'operation-route-lan-remove', 'projection' => NativeRouteLanRemovalProjection::class,
        'workflow_inputs' => true, 'terminal_result' => ['job' => 'withdrawal', 'step' => 'remove-lan'],
    ],
    'route.caddy_converge' => [
        'workflow' => 'operation-route-caddy', 'projection' => NativeCaddyPublicationProjection::class,
        'workflow_inputs' => true, 'terminal_result' => ['job' => 'completion', 'step' => 'result'],
    ],
    'route.private_dns_publish' => [...$publication, 'primitive' => DirectRoutePublication::class],
    ...array_fill_keys(RouteOperationProjection::Kinds, [
        'workflow' => 'operation-route-verification',
        'writer_config' => 'swamp.direct_writers',
        'writer_state' => 'route_verification_writer',
        'projection' => RouteOperationProjection::class,
    ]),
];
