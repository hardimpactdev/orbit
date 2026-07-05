<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Models\Node;
use App\Services\RemoteShell\RemoteLocalExecutor;
use RuntimeException;

final readonly class RemoteAppSecurityRepair
{
    public function __construct(
        private RemoteLocalExecutor $localExecutor,
    ) {}

    public function repair(Node $node, string $user, string $home, string $path): void
    {
        $result = $this->localExecutor->runInternal(
            node: $node,
            commandName: 'internal:app-security:repair',
            arguments: [$user, $home, $path],
            transportOptions: [
                'metadata' => [
                    'ORBIT_OPERATION_ID' => 'app-security.repair',
                ],
                'strict' => true,
                'timeout' => 120,
            ],
        );

        if (! $result->successful()) {
            $message = trim($result->errorOutput());
            $message = $message !== '' ? $message : 'App security repair failed.';

            throw new RuntimeException($message);
        }
    }
}
