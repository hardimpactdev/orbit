<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\RemoteShell\RemoteLocalExecutor;

final readonly class RemoteAppSourceCreator
{
    public function __construct(
        private RemoteLocalExecutor $localExecutor,
    ) {}

    public function create(Node $node, string $user, string $path, ?string $repository): RemoteShellResult
    {
        $commandOptions = [];

        if ($repository !== null) {
            $commandOptions['repository'] = $repository;
        }

        return $this->localExecutor->runInternal(
            node: $node,
            commandName: 'internal:app-source:create',
            arguments: [$user, $path],
            commandOptions: $commandOptions,
            transportOptions: [
                'metadata' => [
                    'ORBIT_OPERATION_ID' => 'app-source.create',
                ],
                'strict' => false,
                'timeout' => 300,
            ],
        );
    }
}
