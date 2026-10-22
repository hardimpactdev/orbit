<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\Instances\LaravelRouteInspector;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\RemotePrograms\RemoteProgramCatalog;
use App\Models\Instance;
use Throwable;

final readonly class NativeLaravelRouteInspector implements LaravelRouteInspector
{
    public function __construct(private RemoteProgramRunner $runner, private RemoteProgramCatalog $catalog) {}

    /** @param non-empty-list<string> $prefixes */
    public function hasCollisions(Instance $instance, array $prefixes): bool
    {
        $instance->loadMissing(['node', 'project']);
        $user = $instance->production_user;
        $home = $instance->production_home;
        $version = $instance->selected_php_version;
        if (! $instance->placedOnAppProd() || ! is_string($user) || preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/D', $user) !== 1
            || $home !== "/home/{$user}" || ! is_string($version) || preg_match('/\A[0-9]+\.[0-9]+\z/D', $version) !== 1) {
            throw $this->failure();
        }

        try {
            $result = $this->runner->run(
                $instance->node,
                $this->catalog->program('instances.inspect-source', user: $user),
                ['inspection' => 'laravel_routes', 'home' => $home, 'application' => $instance->applicationDirectory(), 'php' => 'php'.$version, 'prefixes' => $prefixes],
                timeoutSeconds: 60,
            );
            $collision = $result->data['collision'] ?? null;
        } catch (Throwable) {
            throw $this->failure();
        }
        if ($result->outcome !== 'routes_inspected' || ! is_bool($collision)) {
            throw $this->failure();
        }

        return $collision;
    }

    private function failure(): ResourceOperationException
    {
        return new ResourceOperationException('path_proxy.route_inspection_failed', 'Could not inspect the current Laravel release for reserved path collisions.', 409);
    }
}
