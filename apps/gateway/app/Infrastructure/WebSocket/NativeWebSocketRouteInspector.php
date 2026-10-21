<?php

declare(strict_types=1);

namespace App\Infrastructure\WebSocket;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\WebSocket\WebSocketRouteInspector;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;
use Throwable;

final readonly class NativeWebSocketRouteInspector implements WebSocketRouteInspector
{
    public function __construct(private DevelopmentSshExecutor $ssh) {}

    public function hasCollisions(Instance $instance): bool
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
            $result = $this->ssh->execute($instance->node, new RemoteCommand(
                arguments: ['sudo', '-u', $user, '-H', 'python3', '-c', <<<'PYTHON'
                    import json, pathlib, subprocess, sys
                    home = pathlib.Path(sys.argv[1])
                    current = home / 'current'
                    if home.resolve(strict=True) != home or not current.is_symlink():
                        raise SystemExit(42)
                    release = current.resolve(strict=True)
                    application = pathlib.Path(sys.argv[2]).resolve(strict=True)
                    if release.parent != home / 'releases' or not application.is_relative_to(release):
                        raise SystemExit(42)
                    result = subprocess.run([sys.argv[3], 'artisan', 'route:list', '--json', '--no-interaction'],
                                            cwd=application, capture_output=True, timeout=45)
                    if result.returncode != 0:
                        raise SystemExit(42)
                    routes = json.loads(result.stdout)
                    if not isinstance(routes, list) or any(not isinstance(r, dict) or not isinstance(r.get('uri'), str) for r in routes):
                        raise SystemExit(42)
                    # A leading route parameter can also consume either reserved prefix.
                    collision = any(r['uri'].strip('/').split('/')[0] in ('app', 'apps')
                                    or r['uri'].lstrip('/').startswith('{') for r in routes)
                    print('collision' if collision else 'clear')
                    PYTHON, $home, $instance->applicationDirectory(), 'php'.$version],
                maxOutputBytes: 1024,
            ), 'websocket-route-inspection', 'websocket.route_inspection_failed', commandTimeout: 60);
            $state = trim($result->stdout);
        } catch (Throwable) {
            throw $this->failure();
        }
        if (! in_array($state, ['collision', 'clear'], true)) {
            throw $this->failure();
        }

        return $state === 'collision';
    }

    private function failure(): ResourceOperationException
    {
        return new ResourceOperationException('websocket.route_inspection_failed', 'Could not inspect the current Laravel release for /app and /apps route collisions.', 409);
    }
}
