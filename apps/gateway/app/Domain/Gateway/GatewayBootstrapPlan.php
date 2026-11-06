<?php

declare(strict_types=1);

namespace App\Domain\Gateway;

use App\Actions\Gateway\BootstrapGatewayAction;
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

    /**
     * @param  string  $url  The Gateway's own address, `APP_URL`.
     * @param  string  $home  `ORBIT_HOME`, the folder of its state.
     * @param  string  $checkout  `ORBIT_GATEWAY_CHECKOUT`, the `apps/gateway` folder of the release.
     * @param  ?string  $aptProxy  The package cache of the task VM that runs a nested fleet (`TaskVmAptCache`); null reads the archive directly.
     * @return array<string,mixed>
     */
    public static function make(string $url, string $home, string $checkout, ?string $aptProxy = null): array
    {
        $catalog = new NodeBootstrapPackageCatalog;
        $node = new Node;
        $packages = $catalog->forNode($node);
        foreach (self::Roles as $role) {
            $packages = [...$packages, ...$catalog->forRole($node, $role)];
        }
        // The ready copies of a nested fleet edit the Gateway database with the sqlite3 client (task_vm.ready_copies).
        $packages[] = 'sqlite3';
        // The queue that records the finished runs of Swamp (docs/reference/gateway-queue.md) needs a Redis server; this Gateway's own, on loopback.
        $packages[] = 'valkey-server';
        $build = PhpBuildInstaller::input(new RecipeLoader()->source(), [self::Php], pcov: false, extras: 'keep', fpm: 'all', opentelemetry: false);

        return [
            'packages' => array_values(array_unique(array_diff($packages, ['caddy']))),
            'apt_proxy' => $aptProxy,
            'caddy_package' => NativeCaddyPackageInput::make(),
            'directories' => [['path' => '/var/cache/orbit/role-php', 'owner' => 'root', 'group' => 'root', 'mode' => '0755']],
            'bun' => null,
            'php_packages' => PhpBuildInstaller::packageFiles($build),
            'environment' => self::environment((string) file_get_contents(base_path('.env.example')),
                ['APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_URL' => $url, 'ORBIT_HOME' => $home, 'ORBIT_GATEWAY_CHECKOUT' => $checkout,
                    // Named, not implied: the ready copies and the start of a nested fleet read the database from the `.env`.
                    'DB_DATABASE' => rtrim($home, '/').'/gateway.sqlite',
                    'ORBIT_GATEWAY_QUEUE_CONNECTION' => BootstrapGatewayAction::QueueConnection]),
        ];
    }

    /**
     * The `.env` of the new Gateway: the example file with production values. The application key stays empty; the machine makes it.
     *
     * @param  array<string,string>  $values
     */
    public static function environment(string $example, array $values): string
    {
        $lines = [];
        foreach (explode("\n", $example) as $line) {
            $lines[] = preg_match('/^([A-Z][A-Z0-9_]*)=/', $line, $match) === 1 && isset($values[$match[1]]) ? $match[1].'='.$values[$match[1]] : $line;
        }

        return implode("\n", $lines);
    }
}
