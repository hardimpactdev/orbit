<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Nodes\AdmitNodeRoleOperationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use Illuminate\Console\Command;

/**
 * Admits a role add or converge from the Gateway itself, the way `node:role:add` does through the API. A new Gateway has no web
 * site until its own `gateway` role is applied, so this is how that first role is admitted (swamp/ansible/tasks/task-vm-fleet-build.yml).
 */
final class RoleAddCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:role-add
        {node : Node name}
        {role : Role name}
        {--converge : Converge a role the Node already has}';

    #[\Override]
    protected $description = 'Admit a role add or converge for a Node from the Gateway itself.';

    public function handle(AdmitNodeRoleOperationAction $roles): int
    {
        $node = Node::query()->where('name', (string) $this->argument('node'))->first();
        $role = RoleName::tryFrom((string) $this->argument('role'));
        if (! $node instanceof Node || $role === null) {
            $this->error('The Node or the role is unknown.');

            return self::FAILURE;
        }

        try {
            $operation = $roles->add($node, $role, (bool) $this->option('converge'));
        } catch (ResourceOperationException $exception) {
            $this->error("{$exception->errorCode}: {$exception->getMessage()}");

            return self::FAILURE;
        }
        $this->line("Operation #{$operation->id} {$operation->kind} {$operation->status->value}");

        return self::SUCCESS;
    }
}
