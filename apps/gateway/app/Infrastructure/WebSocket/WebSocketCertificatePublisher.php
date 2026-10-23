<?php

declare(strict_types=1);

namespace App\Infrastructure\WebSocket;

use App\Infrastructure\Caddy\RoleCaddyCertificate;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Node;
use SensitiveParameter;

/** Publishes and removes the Orbit CA leaf certificate of the websocket role on its Node. */
final readonly class WebSocketCertificatePublisher
{
    public function __construct(private RoleCaddyCertificate $certificate) {}

    public function publish(Node $node, string $certificatePem, #[SensitiveParameter] string $privateKeyPem): CommandResult
    {
        return $this->certificate->publish($node, WebSocketFootprint::CertificateCurrentDirectory, 'reverb', WebSocketFootprint::CaddyServiceName, $certificatePem, $privateKeyPem);
    }

    public function remove(Node $node): CommandResult
    {
        return $this->certificate->remove($node, WebSocketFootprint::CertificateCurrentDirectory);
    }
}
