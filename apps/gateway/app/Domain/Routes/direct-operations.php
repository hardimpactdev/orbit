<?php

declare(strict_types=1);
use App\Domain\Routes\DirectRoutePublication;
use App\Domain\Routes\RoutePublicationProjection;

$publication = [
    'workflow' => 'operation-route-publication',
    'writer_config' => 'swamp.route_publication_writers',
    'writer_state' => 'route_publication_writer',
    'projection' => RoutePublicationProjection::class,
    'primitive' => DirectRoutePublication::class,
];

return [
    'route.caddy_converge' => $publication,
    'route.private_dns_publish' => $publication,
];
