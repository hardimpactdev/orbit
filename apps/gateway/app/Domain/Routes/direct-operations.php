<?php

declare(strict_types=1);
use App\Domain\Routes\NativeCaddyPublicationProjection;
use App\Domain\Routes\NativeRouteConvergenceProjection;
use App\Domain\Routes\NativeRouteRemovalProjection;
use App\Domain\Routes\NativeRouteTargetProjection;
use App\Domain\Routes\NativeRouteUpdateProjection;
use App\Domain\Routes\RouteOperationProjection;

return [
    'route.update' => [
        // The end check is the first TLS probe: every effectful update probes a serving Node or the Router.
        'workflow' => 'operation-route-update', 'projection' => NativeRouteUpdateProjection::class,
        'workflow_inputs' => true, 'terminal_result' => ['job' => 'update', 'step' => 'Probe the site over TLS-0', 'native' => true],
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^route-[a-f0-9]{16}-forward-route_material_[0-9]+$']],
    ],
    // The end check is the first TLS probe: every effectful create probes at least one Node, and a failed probe fails the run.
    'route.create' => [
        'workflow' => 'operation-route-create', 'projection' => NativeRouteConvergenceProjection::class,
        'workflow_inputs' => true, 'terminal_result' => ['job' => 'create', 'step' => 'Probe the site over TLS-0', 'native' => true],
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^route-[a-f0-9]{16}-(forward|restore)-route_material_[0-9]+$']],
    ],
    'route.target_set' => [
        // The end check is the Router switch of a production set, or the one publish step of a development target.
        'workflow' => 'operation-route-target-set', 'projection' => NativeRouteTargetProjection::class,
        'workflow_inputs' => true, 'terminal_result' => ['job' => 'update', 'step' => 'Switch the Router pool-0', 'alternatives' => ['Install cert, publish site-0'], 'native' => true],
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^route-[a-f0-9]{16}-forward-route_material_[0-9]+$']],
    ],
    // The end check is a Node's check that nothing of the Route is left; with --offline the first Nodes may be skipped.
    ...array_fill_keys(['route.destroy', 'route.withdraw'], [
        'workflow' => 'operation-route-removal', 'projection' => NativeRouteRemovalProjection::class,
        'workflow_inputs' => true, 'terminal_result' => ['job' => 'update', 'step' => 'Check nothing is left-0',
            'alternatives' => ['Check nothing is left-1', 'Check nothing is left-2', 'Check nothing is left-3'], 'native' => true],
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^route-[a-f0-9]{16}-forward-route_material_[0-9]+$']],
    ]),
    'route.caddy_converge' => [
        'workflow' => 'operation-route-caddy', 'projection' => NativeCaddyPublicationProjection::class,
        'workflow_inputs' => true, 'terminal_result' => ['job' => 'publication', 'step' => NativeCaddyPublicationProjection::EndCheck, 'native' => true,
            'captures' => ['caddy' => ['job' => 'publication', 'step' => 'Read the input', 'field' => 'caddy']]],
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
