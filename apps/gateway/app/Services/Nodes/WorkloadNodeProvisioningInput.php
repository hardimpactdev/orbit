<?php

declare(strict_types=1);

namespace App\Services\Nodes;

final readonly class WorkloadNodeProvisioningInput
{
    /** @mago-expect lint:excessive-parameter-list */
    public function __construct(
        public string $host,
        public ?string $tld,
        public ?string $sshUser,
        public ?string $gatewayEndpoint,
        public ?string $hostKeyFingerprint,
        public string $platform,
        public string $architecture,
        public ?int $postgresNodeId,
        public ?int $postgresProcessId,
        public ?int $clickhouseNodeId,
        public ?string $s3DataPath,
    ) {}
}
