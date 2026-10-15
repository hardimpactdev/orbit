<?php

declare(strict_types=1);

namespace App\E2E\Value;

/** Topology Node kinds. All kinds launch the same recipe base image as VMs. */
enum RoleImage: string
{
    case Gateway = 'gateway';
    case AppDev = 'app-dev';
    case AppProd = 'app-prod';
    case Operator = 'operator';

    public function alias(): string
    {
        return 'orbit-e2e-base';
    }

    public function isContainer(): bool
    {
        return false;
    }

    public function instanceType(): string
    {
        return 'virtual-machine';
    }

    /**
     * The Orbit roles whose convergence programs are rendered through `orbit:node-programs`.
     * An empty list renders the programs of a roleless Node.
     *
     * @return list<string>
     */
    public function programRoles(): array
    {
        return match ($this) {
            self::Gateway => ['gateway', 'vpn'],
            self::AppDev => ['app-dev'],
            self::AppProd => ['app-prod'],
            self::Operator => [],
        };
    }

    /**
     * The Gateway is never enrolled over SSH: `orbit:bootstrap` registers it locally, so convergence skips
     * the program that prepares a Node for the Gateway's SSH access.
     */
    public function runsBootstrapProgram(): bool
    {
        return $this !== self::Gateway;
    }
}
