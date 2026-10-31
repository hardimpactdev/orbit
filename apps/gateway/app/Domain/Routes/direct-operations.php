<?php

declare(strict_types=1);

use App\Domain\Certificates\CertificateSigningPrimitive;
use App\Domain\Routes\NativeRouteAdmission;
use App\Domain\Routes\RouteOperationProjection;

$projection = ['projection' => RouteOperationProjection::class];
$parent = [...$projection, 'private_material' => NativeRouteAdmission::privateMaterial(), 'primitive' => CertificateSigningPrimitive::class];

return [
    'route.create' => [...$parent, 'workflow' => 'operation-route-create'],
    'route.converge' => [...$parent, 'workflow' => 'operation-route-converge'],
    'route.update' => [...$parent, 'workflow' => 'operation-route-update'],
    'route.target_set' => [...$parent, 'workflow' => 'operation-route-target-set'],
    'route.destroy' => [...$parent, 'workflow' => 'operation-route-removal'],
    'route.withdraw' => [...$parent, 'workflow' => 'operation-route-removal'],
    'route.target_unset' => [...$projection, 'workflow' => 'operation-route-target-unset'],
    'route.lan_remove' => [...$projection, 'workflow' => 'operation-route-lan-remove'],
    'route.caddy_converge' => [...$projection, 'workflow' => 'operation-route-caddy'],
    'route.private_dns_publish' => [...$projection, 'workflow' => 'operation-route-dns'],
    ...array_fill_keys(RouteOperationProjection::Kinds, [...$projection, 'workflow' => 'operation-route-verification']),
];
