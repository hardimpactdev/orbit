<?php

declare(strict_types=1);

namespace App\Infrastructure\Analytics;

use App\Infrastructure\Caddy\RoleCaddyCertificate;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Node;
use SensitiveParameter;

/** Publishes and removes the Orbit CA leaf certificate of the analytics role on its Node. */
final readonly class AnalyticsCertificatePublisher
{
    public function __construct(private RoleCaddyCertificate $certificate) {}

    public function publish(Node $node, string $certificatePem, #[SensitiveParameter] string $privateKeyPem): CommandResult
    {
        return $this->certificate->publish($node, AnalyticsFootprint::CertificateCurrentDirectory, 'analytics', AnalyticsFootprint::CaddyServiceName, $certificatePem, $privateKeyPem);
    }

    public function remove(Node $node): CommandResult
    {
        return $this->certificate->remove($node, AnalyticsFootprint::CertificateCurrentDirectory);
    }
}
