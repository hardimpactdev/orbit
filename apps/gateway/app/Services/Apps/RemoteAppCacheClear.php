<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Node;
use App\Services\RemoteShell\RemoteLocalExecutor;

final readonly class RemoteAppCacheClear
{
    public function __construct(
        private RemoteLocalExecutor $localExecutor,
        private AppRuntimeUser $runtimeUser = new AppRuntimeUser,
    ) {}

    public function clear(Node $node, App $app): RemoteShellResult
    {
        return $this->localExecutor->runInternal(
            node: $node,
            commandName: 'internal:app-cache:clear',
            arguments: [
                rtrim(string: $app->path, characters: '/'),
                $app->php_version,
                $this->runtimeUser->forApp($app),
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
