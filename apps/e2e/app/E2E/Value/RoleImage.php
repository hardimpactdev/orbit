<?php

declare(strict_types=1);

namespace App\E2E\Value;

/**
 * One prepared, identity-free Incus image per Node kind. A topology starts each Node from the role image its
 * catalog entry names; `app-prod-2` is a second Node from the `app-prod` image. A new kind of Node is one case here
 * and one catalog entry.
 */
enum RoleImage: string
{
    case Gateway = 'gateway';
    case AppDev = 'app-dev';
    case AppProd = 'app-prod';
    case Operator = 'operator';

    public const string MANIFEST_PATH = '/var/lib/orbit-e2e/role-image.json';

    public const int MANIFEST_SCHEMA = 1;

    public function alias(): string
    {
        return 'orbit-e2e-'.$this->value;
    }

    public function isContainer(): bool
    {
        return $this === self::Operator;
    }

    /** The Incus instance type, as `incus image list` reports it. */
    public function instanceType(): string
    {
        return $this->isContainer() ? 'container' : 'virtual-machine';
    }

    /**
     * The Orbit roles whose enrollment programs the image runs, through `orbit:node-programs`.
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
     * The harness programs the image runs as root before the Gateway's: name, script in `resources/image/`, and
     * arguments.
     *
     * @return list<array{0:string, 1:string, 2:list<string>}>
     */
    public function harnessPrograms(): array
    {
        return [
            ['orbit-e2e-base', 'role-image.sh', ['base']],
            ...match ($this) {
                self::Gateway => [['orbit-e2e-gateway-prerequisites', 'role-image.sh', ['gateway-prerequisites']]],
                self::Operator => [['orbit-e2e-operator-tooling', 'operator-tooling.sh', []]],
                self::AppDev, self::AppProd => [],
            },
        ];
    }

    /**
     * The Gateway is never enrolled over SSH: `orbit:bootstrap` registers it locally, so its image skips
     * the program that prepares a Node for the Gateway's SSH access.
     */
    public function runsBootstrapProgram(): bool
    {
        return $this !== self::Gateway;
    }
}
