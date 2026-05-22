<?php

declare(strict_types=1);

namespace App\E2E\Support;

final readonly class E2ETopologyCapabilities
{
    /**
     * hostMutation means mutation outside the disposable test environment: the developer machine,
     * a real VM host, or a real cloud resource. Container-internal mutations during Docker
     * seeding are prepared topology state, not host mutation.
     */
    public function __construct(
        public bool $realSsh,
        public bool $systemd,
        public bool $hostMutation,
        public bool $kernelNetworking,
        public bool $dockerSiblingContainers = false,
    ) {}

    public static function vm(): self
    {
        return new self(
            realSsh: true,
            systemd: true,
            hostMutation: true,
            kernelNetworking: true,
            dockerSiblingContainers: true,
        );
    }

    public static function containerFeature(): self
    {
        return new self(
            realSsh: false,
            systemd: false,
            hostMutation: false,
            kernelNetworking: false,
            dockerSiblingContainers: true,
        );
    }

    public function satisfies(self $required): bool
    {
        return (! $required->realSsh || $this->realSsh)
            && (! $required->systemd || $this->systemd)
            && (! $required->hostMutation || $this->hostMutation)
            && (! $required->kernelNetworking || $this->kernelNetworking)
            && (! $required->dockerSiblingContainers || $this->dockerSiblingContainers);
    }
}
