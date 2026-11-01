<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

/** Admitted prospective identity. The actual Node remains unchanged until terminal projection. */
final readonly class NodeEnrollmentCandidate
{
    public function __construct(
        public int $nodeId,
        public string $name,
        public string $user,
        public string $platform,
        public ?string $architecture,
        public string $wireguardIp,
        public string $publicSshHost,
        public int $publicSshPort,
        public ?int $clusterId,
        public ?string $tld,
        public ?string $lanIp,
        public ?string $wireguardEndpointOverride,
        public ?string $dnsServerOverride,
    ) {}
}
