<?php
declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services\OrbitCli;

use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\GetLinkedPackagesRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\LinkPackageRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\UnlinkPackageRequest;
use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Services\OrbitCli\Shared\CommandService;
use HardImpact\Orbit\Core\Services\OrbitCli\Shared\ConnectorService;

/**
 * Service for package linking (local development dependencies).
 */
class PackageService
{
    public function __construct(
        protected ConnectorService $connector,
        protected CommandService $command
    ) {}

    /**
     * Link a package to an app for local development.
     */
    public function packageLink(Environment $environment, string $package, string $app): array
    {
        if ($environment->is_local) {
            $escapedPackage = escapeshellarg($package);
            $escapedApp = escapeshellarg($app);

            return $this->command->executeCommand($environment, "package:link {$escapedPackage} {$escapedApp} --json");
        }

        return $this->connector->sendRequest($environment, new LinkPackageRequest($app, $package));
    }

    /**
     * Unlink a package from an app.
     */
    public function packageUnlink(Environment $environment, string $package, string $app): array
    {
        if ($environment->is_local) {
            $escapedPackage = escapeshellarg($package);
            $escapedApp = escapeshellarg($app);

            return $this->command->executeCommand($environment, "package:unlink {$escapedPackage} {$escapedApp} --json");
        }

        return $this->connector->sendRequest($environment, new UnlinkPackageRequest($app, $package));
    }

    /**
     * List all linked packages for an app.
     */
    public function packageLinked(Environment $environment, string $app): array
    {
        if ($environment->is_local) {
            $escapedApp = escapeshellarg($app);

            return $this->command->executeCommand($environment, "package:linked {$escapedApp} --json");
        }

        return $this->connector->sendRequest($environment, new GetLinkedPackagesRequest($app));
    }
}
