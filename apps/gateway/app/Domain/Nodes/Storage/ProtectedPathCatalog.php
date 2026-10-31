<?php

declare(strict_types=1);

namespace App\Domain\Nodes\Storage;

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\Nodes\ManagedUserAccount;
use Illuminate\Support\Facades\Config;

final readonly class ProtectedPathCatalog
{
    /** @var list<string> */
    private const array SYSTEM_ROOTS = ['/boot', '/dev', '/etc', '/proc', '/run', '/sys', '/usr'];

    /** @var list<string> */
    private const array ORBIT_ROOTS = ['/opt/orbit', '/var/lib/orbit', '/var/www'];

    public function isProtected(StoragePath $path, ManagedUserAccount $account): bool
    {
        $home = StoragePath::tryParse($account->home);

        if (! $home instanceof StoragePath) {
            return true;
        }

        if ($path->equals($home) || $home->isInside($path)) {
            return true;
        }

        foreach ($this->roots() as $root) {
            $protected = StoragePath::tryParse($root);

            if ($protected instanceof StoragePath && $path->overlaps($protected)) {
                return true;
            }
        }

        return $this->isHiddenControlPath($path, $home);
    }

    /** @return list<string> */
    public function roots(): array
    {
        return array_values(array_filter([...self::SYSTEM_ROOTS, ...self::ORBIT_ROOTS, ...$this->gatewayPaths()],
            static fn (string $path): bool => StoragePath::tryParse($path) instanceof StoragePath));
    }

    /**
     * The Gateway checkout and, for a checkout in the release layout, the current link, the
     * releases, and the shared state every release links to.
     *
     * @return list<string>
     */
    private function gatewayPaths(): array
    {
        $checkout = rtrim(Config::string('orbit.gateway_checkout'), '/');

        try {
            $layout = new GatewayReleaseLayout($checkout);
        } catch (GatewayReleaseException) {
            return [$checkout];
        }

        return [$checkout, $layout->currentPath(), $layout->releasesPath(), $layout->sharedPath()];
    }

    public function instanceDefault(ManagedUserAccount $account): ?StoragePath
    {
        $home = StoragePath::tryParse($account->home);

        if (! $home instanceof StoragePath) {
            return null;
        }

        return $home->append('apps');
    }

    private function isHiddenControlPath(StoragePath $path, StoragePath $home): bool
    {
        if (! $path->isInside($home)) {
            return false;
        }

        $relative = substr($path->value, strlen($home->value) + 1);
        $first = explode('/', $relative)[0];

        if (! str_starts_with($first, '.')) {
            return false;
        }

        return true;
    }
}
