<?php

declare(strict_types=1);

namespace App\Domain\Gateway;

use App\Domain\Nodes\NativeCaddyPackageInput;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\MachineSoftware\PhpBuildInstaller;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\Nodes\NodeBootstrapPackageCatalog;
use App\Models\Node;

/**
 * What a fresh machine needs before its own Gateway app can run (docs/cli/tasks/vm-fleet-build.mmd, "Prepare the Gateway"):
 * the apt packages of the roles a new Gateway holds, the pinned Caddy package and the pinned PHP packages. It reads the
 * machine software recipe alone, so it needs no database, and it has the shape of the role prerequisites intent
 * (swamp/ansible/tasks/role-prerequisites.yml).
 */
final readonly class GatewayBootstrapPlan
{
    /** The roles a new Gateway takes on: the Gateway itself, its VPN hub, WebSockets and Swamp. */
    private const array Roles = [RoleName::Gateway, RoleName::Vpn, RoleName::WebSocket, RoleName::Swamp];

    /** The PHP version of the Gateway profile in the recipe. */
    private const string Php = '8.5';

    /** @return array<string,mixed> */
    public static function make(): array
    {
        $catalog = new NodeBootstrapPackageCatalog;
        $node = new Node;
        $packages = $catalog->forNode($node);
        foreach (self::Roles as $role) {
            $packages = [...$packages, ...$catalog->forRole($node, $role)];
        }
        $build = PhpBuildInstaller::input(new RecipeLoader()->source(), [self::Php], pcov: false, extras: 'keep', fpm: 'all', opentelemetry: false);

        return [
            'packages' => array_values(array_unique(array_diff($packages, ['caddy']))),
            'caddy_package' => NativeCaddyPackageInput::make(),
            'directories' => [['path' => '/var/cache/orbit/role-php', 'owner' => 'root', 'group' => 'root', 'mode' => '0755']],
            'bun' => null,
            'php_packages' => PhpBuildInstaller::packageFiles($build),
        ];
    }
}
