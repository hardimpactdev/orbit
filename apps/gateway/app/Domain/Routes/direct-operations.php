<?php

declare(strict_types=1);
use App\Domain\Routes\DirectRoutePublication;
use App\Domain\Routes\RoutePublicationProjection;
use App\Domain\Routes\RouteVerificationProjection;

$publication = [
    'workflow' => 'operation-route-publication',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'route_publication_writer',
    'projection' => RoutePublicationProjection::class,
];

return [
    'route.caddy_converge' => [...$publication, 'workflow' => 'operation-route-caddy'],
    'route.private_dns_publish' => [...$publication, 'primitive' => DirectRoutePublication::class],
    ...array_fill_keys(RouteVerificationProjection::Kinds, [
        'workflow' => 'operation-route-verification',
        'writer_config' => 'swamp.direct_writers',
        'writer_state' => 'route_verification_writer',
        'projection' => RouteVerificationProjection::class,
    ]),
];
