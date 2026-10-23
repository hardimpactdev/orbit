<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Infrastructure;

use App\Infrastructure\Caddy\RoleCaddyCertificate;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Node;
use SensitiveParameter;

/** Publishes and removes the Orbit CA leaf certificate of the AI router on its Node. */
final readonly class AiRouterCertificatePublisher
{
    public function __construct(private RoleCaddyCertificate $certificate) {}

    public function publish(Node $node, string $certificatePem, #[SensitiveParameter] string $privateKeyPem): CommandResult
    {
        return $this->certificate->publish($node, AiRouterFootprint::CertificateCurrentDirectory, 'ai_router', AiRouterFootprint::CaddyServiceName, $certificatePem, $privateKeyPem);
    }

    public function remove(Node $node, string $directory = AiRouterFootprint::CertificateCurrentDirectory): CommandResult
    {
        return $this->certificate->remove($node, $directory);
    }
}
