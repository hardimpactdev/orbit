<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Infrastructure\Caddy\Build\Sources\AnalyticsCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\MetricsCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\WebSocketCaddySiteSource;
use App\Infrastructure\ProxyCli\ProxyCliCaddySiteSource;
use App\Models\Node;

/** Records that every role certificate is on a Node, as each role's certificate step does before its build. */
final class CaddySiteCertificateFixtures
{
    public static function recordAll(Node ...$nodes): void
    {
        $certificates = new CaddySiteCertificates;

        foreach ($nodes as $node) {
            foreach ([WebSocketCaddySiteSource::CertificateSlug, AnalyticsCaddySiteSource::CertificateSlug, ProxyCliCaddySiteSource::CertificateSlug, MetricsCaddySiteSource::CertificateSlug] as $site) {
                $certificates->record($node->id, $site);
            }
        }
    }
}
