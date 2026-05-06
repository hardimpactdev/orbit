<?php

declare(strict_types=1);

namespace App\E2E\Support;

final readonly class E2EBaseProvisioner
{
    public function __construct(
        private IncusProvider $provider,
        private E2EProvisioningBundle $bundle,
    ) {}

    public function provision(E2ERun $run, string $suffix, string $role, ?string $controlUser = null): E2EInstance
    {
        if ($role !== 'control') {
            throw new \RuntimeException("E2E direct provisioning only installs control nodes; got [{$role}].");
        }

        $instance = $run->launchBlank($suffix);

        $this->provider->host->waitForCloudInit($instance->name());
        $instance->waitForAgent();
        $this->provider->host->provisionInstance(
            $instance->name(),
            $role,
            $this->bundle->remotePath(),
            $controlUser,
        );

        return $instance;
    }
}
