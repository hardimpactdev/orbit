<?php

namespace App\Services\LaunchpadCli;

use App\Http\Integrations\Launchpad\Requests\GetLinkedPackagesRequest;
use App\Http\Integrations\Launchpad\Requests\LinkPackageRequest;
use App\Http\Integrations\Launchpad\Requests\UnlinkPackageRequest;
use App\Models\Environment;
use App\Services\LaunchpadCli\Shared\CommandService;
use App\Services\LaunchpadCli\Shared\ConnectorService;

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
