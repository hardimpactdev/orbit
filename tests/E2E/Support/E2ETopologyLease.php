<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

final class E2ETopologyLease
{
    private bool $cleaned = false;

    public function __construct(
        private readonly E2ETopologyKind $kind,
        private readonly E2EInstance $control,
        private readonly ?E2EInstance $gateway,
        private readonly ?E2EInstance $dev,
        private readonly ?E2EInstance $prod,
        private readonly SshKeyPair $sshKeyPair,
    ) {}

    public function kind(): E2ETopologyKind
    {
        return $this->kind;
    }

    public function control(): E2EInstance
    {
        return $this->control;
    }

    public function gateway(): ?E2EInstance
    {
        return $this->gateway;
    }

    public function devApp(): ?E2EInstance
    {
        return $this->dev;
    }

    public function prodApp(): ?E2EInstance
    {
        return $this->prod;
    }

    public function sshKeyPair(): SshKeyPair
    {
        return $this->sshKeyPair;
    }

    public function cleanup(): void
    {
        if ($this->cleaned) {
            return;
        }

        $this->cleaned = true;

        foreach ([$this->control, $this->gateway, $this->dev, $this->prod] as $instance) {
            if ($instance !== null) {
                $instance->delete();
            }
        }
    }
}
