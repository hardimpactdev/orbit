<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

final readonly class E2ETopologyAcquisitionOptions
{
    /**
     * @param  array<string, string>|null  $sshUsers
     */
    public function __construct(
        public ?array $sshUsers = null,
        public bool $startGatewayApi = false,
    ) {}
}
