<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

enum RouteDoctorIssueCode: string implements DoctorIssueCode
{
    case WebSocketCollision = 'route.websocket_collision';
    case WebSocketInspectionFailed = 'route.websocket_inspection_failed';
    case LifecycleNotActive = 'route.lifecycle_not_active';
    case DomainReserved = 'route.domain_reserved';
    case DnsMismatch = 'route.dns_mismatch';
    case CertificateMismatch = 'route.certificate_mismatch';
    case CaddyMismatch = 'route.caddy_mismatch';
    case UpstreamUnreachable = 'route.upstream_unreachable';
    case NodeUnreachable = 'route.node_unreachable';
    case InspectionFailed = 'route.inspection_failed';

    public function code(): string
    {
        return $this->value;
    }

    public function family(): DoctorFamily
    {
        return DoctorFamily::Route;
    }
}
