<?php

declare(strict_types=1);

namespace App\Services\Firewall;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\RemoteShell\RemoteLocalExecutor;

final readonly class RemoteFirewallRule
{
    public function __construct(
        private RemoteLocalExecutor $localExecutor,
    ) {}

    /**
     * @param  array<string, mixed>  $shape
     */
    public function apply(Node $node, array $shape): RemoteShellResult
    {
        return $this->run($node, 'apply', $shape);
    }

    /**
     * @param  array<string, mixed>  $shape
     */
    public function delete(Node $node, array $shape): RemoteShellResult
    {
        return $this->run($node, 'delete', $shape);
    }

    /**
     * @param  array<string, mixed>  $shape
     */
    private function run(Node $node, string $action, array $shape): RemoteShellResult
    {
        return $this->localExecutor->runInternal(
            node: $node,
            commandName: 'internal:firewall-rule',
            transportOptions: [
                'input' => json_encode([
                    'action' => $action,
                    'shape' => $shape,
                ], JSON_THROW_ON_ERROR),
                'metadata' => [
                    'ORBIT_OPERATION_ID' => "firewall.rule.{$action}",
                ],
                'timeout' => 60,
            ],
        );
    }
}
