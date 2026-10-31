<?php

declare(strict_types=1);
use App\Domain\Routes\DirectRoutePublication;
use App\Domain\Routes\RouteOperationProjection;

$publication = [
    'workflow' => 'operation-route-publication',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'route_publication_writer',
    'projection' => RouteOperationProjection::class,
];

return [
    'route.target_unset' => [
        'workflow' => 'operation-route-target-unset', 'writer_config' => 'swamp.direct_writers',
        'writer_state' => 'route_target_unset_writer', 'projection' => RouteOperationProjection::class,
    ],
    'route.lan_remove' => [
        'workflow' => 'operation-route-lan-remove', 'writer_config' => 'swamp.direct_writers',
        'writer_state' => 'route_lan_writer', 'projection' => RouteOperationProjection::class,
    ],
    'route.caddy_converge' => [...$publication, 'workflow' => 'operation-route-caddy'],
    'route.private_dns_publish' => [...$publication, 'primitive' => DirectRoutePublication::class],
    ...array_fill_keys(RouteOperationProjection::Kinds, [
        'workflow' => 'operation-route-verification',
        'writer_config' => 'swamp.direct_writers',
        'writer_state' => 'route_verification_writer',
        'projection' => RouteOperationProjection::class,
    ]),
];
