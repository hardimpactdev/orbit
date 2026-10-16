<?php

declare(strict_types=1);

namespace App\Domain\Extensions;

use App\Domain\Analytics\AnalyticsHostname;
use App\Domain\ProxyCli\ProxyCliHostname;
use App\Domain\WebSocket\WebSocketHostname;

/** Core's identities. Plug-in boundaries check these before accepting contributed identities. */
final readonly class ReservedNames
{
    public const array CaddySources = ['gateway', 'metrics', 'service-metrics', 'app-dev', 'app-prod', 'ingress', 'websocket', 'analytics'];

    public const string WebsocketCertificate = 'websocket';

    public const string AnalyticsCertificate = 'analytics';

    public const string MetricsCertificate = 'metrics';

    public const array CertificateSlugs = [self::WebsocketCertificate, self::AnalyticsCertificate, self::MetricsCertificate];

    public const array PrivateDnsNames = ['gateway.orbit', 'metrics.orbit', WebSocketHostname::Value, AnalyticsHostname::Value, ProxyCliHostname::Value];

    public const string AgentArtifact = 'agent';

    public const string AnnotatorArtifact = 'annotator';

    public const string CaddyArtifact = 'caddy';

    public const string CaddyPackageArtifact = 'caddy-package';

    public const string PrivateDnsArtifact = 'private-dns';

    public const string ProxyCliArtifact = 'proxycli';

    public const string RouteResidueArtifact = 'route-residue';

    public const string TmpfilesArtifact = 'tmpfiles';

    public const array FootprintArtifacts = [self::AgentArtifact, self::AnnotatorArtifact, self::CaddyArtifact, self::CaddyPackageArtifact, self::PrivateDnsArtifact, self::ProxyCliArtifact, self::RouteResidueArtifact, self::TmpfilesArtifact];
}
