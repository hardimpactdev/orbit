<?php

declare(strict_types=1);
use App\Domain\Routes\NativeCaddyPublicationProjection;
use App\Domain\Routes\NativeRouteConvergenceProjection;
use App\Domain\Routes\NativeRouteLanRemovalProjection;
use App\Domain\Routes\NativeRouteRemovalProjection;
use App\Domain\Routes\NativeRouteTargetProjection;
use App\Domain\Routes\NativeRouteUpdateProjection;
use App\Domain\Routes\RouteOperationProjection;

return [
    'route.update' => [
        'workflow' => 'operation-route-update', 'projection' => NativeRouteUpdateProjection::class,
        'workflow_inputs' => true, 'terminal_result' => ['job' => 'update', 'step' => 'result'],
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^route-[a-f0-9]{16}-(forward|restore)-route_material_[0-9]+$']],
    ],
    'route.create' => [
        'workflow' => 'operation-route-create', 'projection' => NativeRouteConvergenceProjection::class,
        'workflow_inputs' => true, 'terminal_result' => ['job' => 'update', 'step' => 'result'],
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^route-[a-f0-9]{16}-(forward|restore)-route_material_[0-9]+$']],
    ],
    'route.target_set' => [
        'workflow' => 'operation-route-target-set', 'projection' => NativeRouteTargetProjection::class,
        'workflow_inputs' => true, 'terminal_result' => ['job' => 'update', 'step' => 'result'],
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^route-[a-f0-9]{16}-(forward|restore)-route_material_[0-9]+$']],
    ],
    ...array_fill_keys(['route.destroy', 'route.withdraw'], [
        'workflow' => 'operation-route-removal', 'projection' => NativeRouteRemovalProjection::class,
        'workflow_inputs' => true, 'terminal_result' => ['job' => 'update', 'step' => 'result'],
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^route-[a-f0-9]{16}-(forward|restore)-route_material_[0-9]+$']],
    ]),
    'route.target_unset' => [
        'workflow' => 'operation-route-target-unset', 'projection' => RouteOperationProjection::class,
        'workflow_inputs' => true, 'terminal_result' => ['job' => 'confirmation', 'step' => 'result'],
    ],
    'route.lan_remove' => [
        'workflow' => 'operation-route-lan-remove', 'projection' => NativeRouteLanRemovalProjection::class,
        'workflow_inputs' => true, 'terminal_result' => ['job' => 'withdrawal', 'step' => 'remove-lan'],
    ],
    'route.caddy_converge' => [
        'workflow' => 'operation-route-caddy', 'projection' => NativeCaddyPublicationProjection::class,
        'workflow_inputs' => true, 'terminal_result' => ['job' => 'completion', 'step' => 'result'],
    ],
    'route.private_dns_publish' => [
        'workflow' => 'operation-route-publication', 'projection' => RouteOperationProjection::class,
        'workflow_inputs' => true, 'terminal_result' => ['job' => 'publication', 'step' => 'result'],
    ],
    ...array_fill_keys(RouteOperationProjection::Kinds, [
        'workflow' => 'operation-route-verification',
        'projection' => RouteOperationProjection::class, 'workflow_inputs' => true,
        'terminal_result' => ['job' => 'verification', 'step' => 'result'],
    ]),
];
