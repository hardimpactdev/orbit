<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Services\Workspaces\WorkspacePlacement;

final readonly class RemoteAppCacheClear
{
    public function __construct(
        private RunsInternalCommands $localExecutor,
        private AppRuntimeUser $runtimeUser = new AppRuntimeUser,
        private WorkspacePlacement $placement = new WorkspacePlacement,
    ) {}

    public function clear(Node $node, App $app, ?Instance $instance = null): RemoteShellResult
    {
        return $this->clearPath(
            node: $node,
            path: $this->placement->runtimePath($app, $instance),
            phpVersion: $this->placement->runtimePhpVersion($app, $instance),
            runtimeUser: $this->runtimeUser->forApp($app, $instance),
        );
    }

    public function clearPath(
        Node $node,
        string $path,
        string $phpVersion,
        string $runtimeUser,
    ): RemoteShellResult {
        return $this->localExecutor->runInternal(
            node: $node,
            commandName: 'internal:app-cache:clear',
            arguments: [
                rtrim(string: $path, characters: '/'),
                $phpVersion,
                $runtimeUser,
            ],
            transportOptions: [
                'metadata' => [
                    'ORBIT_OPERATION_ID' => 'app.cache.clear',
                ],
                'timeout' => 60,
            ],
        );
    }
}
