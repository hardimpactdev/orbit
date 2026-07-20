<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\Project;
use App\Services\RemoteShell\RunsInternalCommands;

final readonly class RemoteAppCacheClear
{
    public function __construct(
        private RunsInternalCommands $localExecutor,
        private AppRuntimeUser $runtimeUser = new AppRuntimeUser,
    ) {}

    public function clear(Node $node, Project $app): RemoteShellResult
    {
        return $this->clearPath(
            node: $node,
            path: $app->path,
            phpVersion: $app->php_version,
            runtimeUser: $this->runtimeUser->forApp($app),
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
