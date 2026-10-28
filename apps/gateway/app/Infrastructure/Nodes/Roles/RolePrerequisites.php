<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes\Roles;

use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\UbuntuRelease;
use App\Infrastructure\Nodes\NodeBootstrapPackageCatalog;
use App\Models\Node;

/** Pure role prerequisite payloads shared with Node enrollment and integration plans. */
final readonly class RolePrerequisites
{
    public function __construct(private NodeBootstrapPackageCatalog $packages = new NodeBootstrapPackageCatalog) {}

    public function needsCaddy(Node $node, RoleName $role): bool
    {
        return in_array('caddy', $this->packages->forRole($node, $role), strict: true);
    }

    /** @return array{user: string, group: string, home: string, releases: list<string>, packages: list<string>, apps_directory: bool, composer_home: bool} */
    public function input(Node $node, RoleName $role, ManagedUserAccount $account): array
    {
        return [
            'user' => $account->user,
            'group' => $account->group,
            'home' => $account->home,
            'releases' => array_map(static fn (UbuntuRelease $release): string => $release->value, UbuntuRelease::forRole($role)),
            'packages' => $this->packages->forRole($node, $role),
            'apps_directory' => $role === RoleName::AppDev,
            'composer_home' => self::application($role),
        ];
    }

    private static function application(RoleName $role): bool
    {
        return in_array($role, [RoleName::AppDev, RoleName::AppProd], strict: true);
    }
}
