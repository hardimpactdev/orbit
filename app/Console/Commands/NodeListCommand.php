<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Node;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('node:list')]
#[Description('List nodes registered in the gateway registry')]
class NodeListCommand extends Command
{
    public function handle(): int
    {
        $nodes = Node::query()
            ->orderBy('role')
            ->orderBy('name')
            ->get(['name', 'role', 'host', 'ssh_user', 'orbit_path', 'status', 'is_local']);

        if ($nodes->isEmpty()) {
            $this->info('No nodes registered.');

            return self::SUCCESS;
        }

        $this->table(
            ['Name', 'Role', 'Host', 'SSH User', 'Orbit Path', 'Status', 'Local'],
            $nodes->map(fn (Node $node): array => [
                $node->name,
                $node->role,
                $node->host,
                $node->ssh_user,
                $node->orbit_path,
                $node->status,
                $node->is_local ? 'yes' : 'no',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
